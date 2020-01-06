<?php

declare (strict_types=1);

namespace App\Controller\FrontOffice\User;

use App\Controller\FlashType;
use App\Controller\CoreResponseProvider;
use App\Controller\SecurityAwareTrait;
use App\Domain\Bridge\Query\Get;
use App\Domain\User\UserRepository;
use App\Infrastructure\EventStore\Repository\Domain\EventStoreUserRepository;
use App\Infrastructure\Projection\Waiter;
use App\Infrastructure\Service\Routing\Router;
use App\Infrastructure\Service\Session\FlashDriver;
use App\Infrastructure\Service\Translation\Translator;
use App\Infrastructure\Utils\Arrays;
use App\Infrastructure\Utils\Objects;
use Escqrs\ServiceBus\QueryBus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Security;
use Twig\Environment;

/**
 * Class ProfileController
 */
final class ProfileController
{
    use SecurityAwareTrait;

    /** @var Environment */
    protected $templateEngine;

    /** @var CoreResponseProvider */
    protected $coreResponseProvider;

    /** @var Router */
    protected $router;

    /** @var QueryBus */
    protected $queryBus;

    /** @var FlashDriver */
    protected $flashDriver;

    /** @var Translator */
    protected $translator;

    /** @var EventStoreUserRepository */
    protected $userRepository;

    /** @var Waiter */
    protected $projectionWaiter;

    public function __construct(
        Security $security,
        Environment $templateEngine,
        CoreResponseProvider $coreResponseProvider,
        Router $router,
        QueryBus $queryBus,
        FlashDriver $flashDriver,
        Translator $translator,
        UserRepository $userRepository,
        Waiter $projectionWaiter
    )
    {
        $this->security = $security;
        $this->templateEngine = $templateEngine;
        $this->coreResponseProvider = $coreResponseProvider;
        $this->router = $router;
        $this->queryBus = $queryBus;
        $this->flashDriver = $flashDriver;
        $this->translator = $translator;
        $this->userRepository = $userRepository;
        $this->projectionWaiter = $projectionWaiter;
    }

    public function profileAction(Request $request, string $id): Response
    {
        // this page may be reached after a registration, so let's wait a bit, just to be sure
        $this->projectionWaiter->waitForAggregate('dty.user_finder', 'id', $id);
        $this->projectionWaiter->waitForAggregate('se.user_finder', 'id', $id);

        if (!$user = $this->userRepository->get($id)) {
            $this->flashDriver->add(
                FlashType::WARNING,
                $this->translator->trans('back.controller.profile.redirect_message.user_does_not_exist', [], 'front_office-user', $this->coreResponseProvider->getLocale())
            );

            return $this->router->elsewhere();
        };

        if ($user->locked()) {
            $this->flashDriver->add(
                FlashType::WARNING,
                $this->translator->trans('back.controller.profile.redirect_message.user_is_now_locked', [], 'front_office-user', $this->coreResponseProvider->getLocale())
            );

            return $this->router->elsewhere();
        };

        if (!(
            $this->getUser(true)->isOrganizationDirectorOfUser($user)
            || $this->getUser()->isOrganizationManagerOfUser($user)
            || $this->getUser()->id() === $user->id(true)
        )) {
            $this->flashDriver->add(
                FlashType::WARNING,
                $this->translator->trans('back.controller.profile.redirect_message.not_director_or_manager', [], 'front_office-user', $this->coreResponseProvider->getLocale())
            );

            return $this->router->elsewhere();
        } else if (!$this->getUser()->data()->organization->id === $user->organizationId(true)) {
            $this->flashDriver->add(
                FlashType::WARNING,
                $this->translator->trans('back.controller.profile.redirect_message.not_same_organization', [], 'front_office-user', $this->coreResponseProvider->getLocale())
            );

            return $this->router->elsewhere();
        }

        $queryString = <<<'GQL'
query ($id: String) {
    user (id: $id) {
        id
        organization {
            id
          	workspaces (first: -1) {
                edges {
                    node {
                        id
                        name
                    }
                }
            }
        }
        account {
            email
            phone
            activated
            locked {
                authorId
                at
            }
            lastActivationPoke {
                at
            }
        }
        profile {
            names {
                firstname
                lastname
                both
            }
            organizationBadges {
                workspace {
                    id
                    name
                }
                badge
            }
            portrait {
                src
            }
            job
        }
        settings {
            notificationSettings {
                subject
                state
            }
        }
    }
}
GQL;

        $this->queryBus
            ->dispatch(Get::with($queryString, [
                'id' => $id
            ]))
            ->done(function (?\stdClass $result = null) use (&$user) {
                $user = $result->user;
            });

        /** @var \stdClass $user */
        $cie = [
            'User' => [
                'OrganizationBadgeWasAddedToUser' => [$user->id => true],
                'OrganizationBadgeWasRemovedFromUser' => [$user->id => true],
                'UserAccountWasChanged' => [$user->id => 'patch.email:|patch.phone:'],
                'UserNotificationSettingWasChanged' => [$user->id => true],
                'UserProfileWasChanged' => [$user->id => 'patch.names:|patch.job:|patch.portrait:'],
                'UserWasActivated' => [$user->id => true],
                'UserWasLocked' => [$user->id => true],
                'UserWasPokedForActivation' => [$user->id => true],
            ],
        ];
        if ($organization = Objects::get($user, 'organization'))
            $cie = Arrays::set($cie, "Workspace.WorkspaceWasCreated.organization_id:{$user->organization->id}", true);
        if ($workspaces = Objects::get($user, 'organization.workspaces.edges'))
            foreach ($workspaces as $workspace)
                $cie = Arrays::set($cie, "Workspace.WorkspaceWasChanged.{$workspace->node->id}", 'patch.name:');

        $data = $this->coreResponseProvider->core([
            'user' => $user,
        ], [
            'title' => $this->title([
                'names' => $user->profile->names->both
            ]),
            'cacheInvalidationEvents' => [
                'payload' => $cie,
            ],
        ]);

        if ($request->isXmlHttpRequest() && $request->headers->has('X-JSON-Core'))
            return new JsonResponse($data);

        return new Response($this->templateEngine->render('office/layout.html.twig', ['data' => $data]));
    }

    protected function title(array $parameters = [])
    {
        return [
            'id' => 'back.controller.profile.title',
            'parameters' => $parameters,
            'domain' => 'front_office-user'
        ];
    }
}
