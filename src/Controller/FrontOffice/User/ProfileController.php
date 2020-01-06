<?php

declare (strict_types=1);

namespace App\Controller\FrontOffice\User;

// .
// .
// .

/**
 * Class ProfileController
 */
final class ProfileController
{
    // .
    // .
    // .

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
}
