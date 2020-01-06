<?php

namespace App\Controller;

use App\Domain\Bot\ValueObject\BotState;
use App\Domain\Bridge\Query\Get;
use App\Domain\Bridge\ValueObject\DNSLevel4;
use App\Domain\Bridge\ValueObject\File\CompressedFile\CompressedFile;
use App\Domain\Bridge\ValueObject\File\ImageFile\ImageFile;
use App\Domain\Bridge\ValueObject\FlavorId;
use App\Domain\Bridge\ValueObject\Locale;
use App\Domain\Bridge\ValueObject\File\VideoFile\VideoFile;
use App\Domain\Bridge\ValueObject\Socials\SocialNetwork;
use App\Domain\License\ValueObject\LicenseState;
use App\Domain\Organization\ValueObject\OrganizationBadge;
use App\Domain\Organization\ValueObject\OrganizationPlan\OrganizationPlanType;
use App\Domain\ParentTemplate\ValueObject\ParentTemplateState;
use App\Domain\User\ValueObject\UserNotification\UserNotificationSubject;
use App\Domain\User\ValueObject\UserRole;
use App\Domain\Video\Entity\VideoSocialPost\VideoSocialPostState;
use App\Domain\Video\ValueObject\VideoState;
use App\Domain\Video\Video;
use App\Infrastructure\Service\Session\FlashDriver;
use App\Infrastructure\Service\Translation\Translator;
use App\Infrastructure\Utils\Objects;
use App\Security\User;
use Escqrs\Bundle\ServiceBus\QueryBus;
use Escqrs\ServiceBus\Exception\MessageDispatchException;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Infrastructure\Utils\Arrays;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Translation\MessageCatalogue;

class CoreResponseProvider
{
    use SecurityAwareTrait;

    const ROLE_PREVIOUS_ADMIN = 'ROLE_PREVIOUS_ADMIN';
    const NO_CACHE_REQUEST_QUERY_PARAMETER = 'nc';

    /** @var FlashDriver */
    protected $flashDriver;

    /** @var RequestStack */
    protected $requestStack;

    /** @var Translator */
    protected $translator;

    /** @var QueryBus */
    protected $queryBus;

    /** @var Logger */
    protected $logger;

    /** @var array */
    protected $socials;

    /** @var array */
    protected $frontWhitelists;

    /** @var User */
    protected $user;

    public function __construct(
        Security $security,
        FlashDriver $flashDriver,
        RequestStack $requestStack,
        Translator $translator,
        QueryBus $queryBus,
        LoggerInterface $logger,
        array $socials,
        array $frontWhitelists
    )
    {
        $this->security = $security;
        $this->flashDriver = $flashDriver;
        $this->requestStack = $requestStack;
        $this->translator = $translator;
        $this->queryBus = $queryBus;
        $this->logger = $logger;
        $this->socials = $socials;
        $this->frontWhitelists = $frontWhitelists;
    }

    /**
     * Removes no-cache query parameter from any input (absolute url, relative url, path, query string, etc.)
     *
     * Ex:
     *      - ?foo=bar&nc=8734928479 => ?foo=bar
     *      - ?nc=8734928479&foo=bar => ?foo=bar
     *      - /xyz/www/web/app_dev.php/path?foo=bar&nc=8734928479 => /xyz/www/web/app_dev.php/path?foo=bar
     *
     * @param string $input
     * @return string|string[]|null
     */
    protected function clearNoCacheQueryParameter(string $input)
    {
        $input = preg_replace(['/\&' . static::NO_CACHE_REQUEST_QUERY_PARAMETER . '\=\d+/', '/\?' . static::NO_CACHE_REQUEST_QUERY_PARAMETER . '\=\d+$/'], '', $input);
        $input = preg_replace('/\?' . static::NO_CACHE_REQUEST_QUERY_PARAMETER . '\=\d+\&/', '?', $input);
        return $input;
    }

    public function core(array $payload = [], array $patch = [], bool $recursivePatch = true)
    {
        $request = $this->requestStack->getCurrentRequest();
        $appuser = $this->enrichedAppUser($this->impersonatedAppUser($this->getUser()));
        $locale = isset($appuser['_impersonator']) ? $appuser['_impersonator']['account']['locale'] : $this->getLocale();

        // clear no-cache parameter
        $query = $request->query->all();
        unset($query['nc']);

        $replaceMethod = $recursivePatch ? 'array_replace_recursive' : 'array_replace';

        $data = [
            'appuser' => $appuser,
            'flashes' => $this->getFlashes(),
            'title' => null,
            'routing' => [
                'referer' => $request->headers->get('referer') ?: null,
                'office' => getenv('OFFICE') ?: null, // connect
                'query' => $query, // ['foo' => 'bar']
                'route' => $request->get('_route'), // office::domain::path
                'routeParameters' => $request->get('_route_params'), // ['id' => 2]
                'uri' => $this->clearNoCacheQueryParameter($request->getUri()), // http://connect.host.com:8000/xyz/www/web/app_dev.php/path/2?foo=bar
                'uriScheme' => $request->getScheme(), // http
                'uriHttpHost' => $request->getSchemeAndHttpHost(), // connect.host.com:8000
                'uriSchemeAndHttpHost' => $request->getSchemeAndHttpHost(), // http://connect.host.com:8000
                'uriRequestUri' => $this->clearNoCacheQueryParameter($request->getRequestUri()), // /xyz/www/web/app_dev.php/path?foo=bar
                'uriPath' => $request->getPathInfo(), // /path
                'uriBasePath' => $request->getBasePath(), // /xyz/www/web
                'uriBaseUrl' => $request->getBaseUrl(), // /xyz/www/web/app_dev.php
                'uriPathWithQuery' => $this->clearNoCacheQueryParameter($request->getPathInfo() . (count($request->query) ? "?{$request->getQueryString()}" : '')), // /path/2?foo=bar
            ],
            'payload' => $payload,
            'ip' => $request->getClientIp(),
            'locale' => [
                'locale' => $locale,
                'catalogue' => $this->getTranslationCatalogue($locale, getenv('OFFICE'))
            ],
            'workspace' => $this->getWorkspace(),
            'constants' => $this->getConstants(),
            'envs' => $this->getShareableEnvs(),
            'socials' => $this->socials,
            /**
             * Payload cache invalidation events.
             *
             * Possibles values:
             *      - null: non-cacheable and therefore not cached
             *      - empty array []: cacheable and cached forever (until browser window/tab is closed)
             *      - non-empty array[
             *          {domain} => [
             *              {event} => [
             *                  {condition} => {action}
             *                  ...
             *              ]
             *              ...
             *          ]
             *          ...
             *      ]: cacheable and cached as long A and B events don't occur in the current browser window/tab
             *
             * Criteria:
             *      - condition "{id}" -> metadata _aggregate_id must be "id"
             *      - condition "{path}:{value}" -> payload "path" value must be "value" (value may be "null", as in "organization_id:null")
             *      - condition "{path}:" -> payload must have "path" set, whatever the value
             *      - action TRUE -> go ahead
             *      - action "{A}|{B}|..." -> go ahead if it verifies at least one of A, B, etc.. Ex: "id748" condition + "patch.name:Sweet|patch.phone:|patch.job:" action would give global condition (id748 && (patch.name: || organization_id:id657))
             */
            'cacheInvalidationEvents' => [
                'appuser' => null,
                'payload' => null,
            ],
        ];

        if (getenv('OFFICE') !== 'back-office' && $appuser) {
            $cie = [
                'User' => [
                    'UserProfileWasChanged' => [$appuser['id'] => 'patch.portrait:|patch.names:'],
                    'OrganizationBadgeWasAddedToUser' => [$appuser['id'] => true],
                    'OrganizationBadgeWasRemovedFromUser' => [$appuser['id'] => true],
                    'NotificationsWereRemovedFromUser' => [$appuser['id'] => true],
                    'UserNotificationsWereMarkedAsBeingRead' => [$appuser['id'] => true],
                    'UserWasNotified' => [$appuser['id'] => true],
                    'UserAccountWasChanged' => [$appuser['id'] => true],
                ],
            ];
            if ($organization = Arrays::get($appuser, 'organization')) {
                $cie = Arrays::set($cie, "User.OrganizationBadgeWasAddedToUser.organization_id:{$organization['id']}", 'organization_badge.badge:' . OrganizationBadge::DIRECTOR);
                $cie = Arrays::set($cie, "User.OrganizationBadgeWasRemovedFromUser.organization_id:{$organization['id']}", 'organization_badge.badge:' . OrganizationBadge::DIRECTOR);
                $cie = Arrays::set($cie, "Organization.OrganizationPlanWasChanged.{$organization['id']}", true);
                $cie = Arrays::set($cie, "Organization.OrganizationPlanWasStarted.{$organization['id']}", true);
                $cie = Arrays::set($cie, "Organization.OrganizationWasCredited.{$organization['id']}", true);
                $cie = Arrays::set($cie, "Organization.OrganizationWasDebited.{$organization['id']}", true);
                if ($workspaces = Arrays::get($organization, 'workspaces.edges'))
                    foreach ($workspaces as $workspace)
                        $cie = Arrays::set($cie, "Workspace.WorkspaceWasChanged.{$workspace['node']['id']}", 'patch.name:');
            }

            $data['cacheInvalidationEvents']['appuser'] = array_replace_recursive($cie, $data['cacheInvalidationEvents']['appuser'] ?: []);
        }

        return $replaceMethod($data, $patch);
    }

    /**
     * https://danim-docs.readthedocs.io/en/latest/dev/language-tracks.html
     *
     * @return string
     */
    public function getLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $user = $this->getUser();

        if ($dnsLevel4 = getenv('DNS_LEVEL_4')) {
            $ans = DNSLevel4::localeTable()[$dnsLevel4];
        } else if (!$user) {
            if ($locale = $request->cookies->get(Cookie::LOCALE())) $ans = $locale;
            else if ($locale = getenv('BROWSER_LOCALE')) $ans = $locale;
            else $ans = getenv('DEFAULT_LOCALE');
        } else {
            $ans = $user->locale();
        }

        return $ans;
    }

    public function getWorkspace(): ?\stdClass
    {
        $user = $this->getUser();

        if (!$user || !$user->data()->organization) return null;

        $ans = $user->isOrganizationDirector() ? null : $user->data()->workspaces->edges[0]->node;

        $cookieWorkspaceId = $this->requestStack->getCurrentRequest()->cookies->get(Cookie::WORKING_WORKSPACE_ID()) ?: null;
        if ($cookieWorkspaceId
            && $edge = Arrays::find($this->getUser()->isOrganizationDirector() ? $user->data()->organization->workspaces->edges : $user->data()->workspaces->edges, function ($edge) use ($cookieWorkspaceId) {
                return $edge->node->id === $cookieWorkspaceId;
            })
        ) $ans = $edge->node;

        return $ans;
    }

    public function getFlashes(bool $flatten = true): array
    {
        $now = time();

        $flashbag = $this->flashDriver->all();

        if (!$flatten) return $flashbag;

        $flashes = [];

        foreach ($flashbag as $type => $arr) {
            foreach (array_unique($arr) as $message) {
                $flashes[] = [
                    'type' => $type,
                    'message' => $message,
                    'timestamp' => $now,
                    'crossPage' => false
                ];
            }
        }

        return $flashes;
    }

    public function getTranslationCatalogue($locale, string $office, bool $fallback = true): array
    {
        /** @var MessageCatalogue $catalogue */
        $catalogue = $this->translator->getCatalogue($locale);
        $messages = $catalogue->all();
        if ($fallback) {
            while ($catalogue = $catalogue->getFallbackCatalogue()) {
                $messages = array_replace_recursive($catalogue->all(), $messages);
            }
        }

        $messageKeys = Arrays::filter(array_keys($messages), function ($messageKey) use ($office) {
            foreach (['^bridge', str_replace('-', '_', $office) . '.*'] as $regex) {
                if (preg_match("#{$regex}#", $messageKey)) return true;
            }
            return false;
        });

        return array_intersect_key($messages, array_flip($messageKeys));
    }

    public function getConstants(): array
    {
        $class2constants = function (string $class) {
            $reflectionClass = new \ReflectionClass($class);
            return Arrays::each($reflectionClass->getConstants(), function ($value, $name) {
                return [
                    'name' => $name,
                    'value' => $value,
                ];
            });
        };

        $domainConstants = [
            // Bot
            'BotState' => [
                'states' => $class2constants(BotState::class),
                'hex_colors' => BotState::hexColors(),
            ],
            // General
            //      File
            'CompressedFile' => $class2constants(CompressedFile::class),
            'Cookie' => Cookie::getConstants(),
            'ImageFile' => $class2constants(ImageFile::class),
            'VideoFile' => $class2constants(VideoFile::class),
            'FlavorId' => [
                'ids' => FlavorId::getValues(),
                'flavors' => FlavorId::details(),
            ],
            'Locale' => [
                'locales' => $class2constants(Locale::class),
                'dnsLevel4Table' => Locale::dnsLevel4Table(),
            ],
            'SocialNetwork' => [
                'networks' => $class2constants(SocialNetwork::class),
                'details' => SocialNetwork::details(),
            ],
            // License
            'LicenseState' => [
                'states' => $class2constants(LicenseState::class),
                'hex_colors' => LicenseState::hexColors()
            ],
            // Organization
            'OrganizationBadge' => [
                'badges' => $class2constants(OrganizationBadge::class),
                'tree' => OrganizationBadge::hierarchicalTree()
            ],
            'OrganizationPlanType' => [
                'types' => $class2constants(OrganizationPlanType::class),
            ],
            // ParentTemplate
            'ParentTemplateState' => [
                'states' => $class2constants(ParentTemplateState::class),
                'hex_colors' => ParentTemplateState::hexColors(),
            ],
            // User
            'UserRole' => [
                'roles' => $class2constants(UserRole::class),
                'tree' => UserRole::hierarchicalTree()
            ],
            'UserNotificationSubject' => [
                'subjects' => $class2constants(UserNotificationSubject::class),
                'details' => UserNotificationSubject::details(),
            ],
            // Video
            'Video' => $class2constants(Video::class),
            'VideoState' => [
                'states' => $class2constants(VideoState::class),
                'hex_colors' => VideoState::hexColors(),
            ],
            'VideoSocialPostState' => [
                'states' => $class2constants(VideoSocialPostState::class),
            ],
        ];

        // Globals. Ex: DATE_ATOM
        $globalConstants = [];

        return array_replace_recursive(
            $domainConstants,
            $globalConstants
        );
    }

    public function getShareableEnvs(): array
    {
        return array_combine(
            $this->frontWhitelists['envs'],
            Arrays::each($this->frontWhitelists['envs'], function ($env) {
                return getenv($env);
            })
        );
    }

    /**
     * If appuser is impersonated, adds metafields to it
     * Else returns appuser as it is
     *
     * @param User|null $user
     * @return array|null
     */
    protected function impersonatedAppUser(?User $user): ?array
    {
        if (!$user) return null;

        $user = $user->normalize();

        $token = $this->security->getToken();

        if ($token instanceof SwitchUserToken) {
            $impersonator = $token->getOriginalToken()->getUser()->normalize();
            $user['_impersonator'] = $impersonator;
        }

        return $user;
    }

    public function enrichedAppUser(?array $user): ?array
    {
        if (!$user) return null;

        $profileQueryString = <<<GQL
newNotifications: notifications (sort: "createdAt:desc" new: true) {
    count
    edges {
        node {
            id
            workspaceId
        }
    }
}
notifications {
    count
}
GQL;

        // workspaceNotifications
        if ($workspace = $this->getWorkspace()) {
            $profileQueryString .= <<<GQL
workspaceNotifications: notifications (inWorkspace: "{$workspace->id}") {
    count
}
GQL;
        }

        $queryString = <<<GQL
query (\$id: String) {
    user (id: \$id) {
        id
        profile {
            $profileQueryString
        }
    }
}
GQL;

        $layer = null;

        try {
            $this->queryBus
                ->dispatch(Get::with($queryString, [
                    'id' => $user['id']
                ]))
                ->done(function (?\stdClass $result = null) use (&$layer) {
                    if (isset($result->user)) $layer = $result->user;
                });
        } catch (MessageDispatchException $ex) {
            $layer = null;
            $this->logger->critical(__METHOD__ . ': ' . $ex->getPrevious()->getMessage() . ' ' . $ex->getPrevious()->getTraceAsString(), ['exception' => $ex->getPrevious()]);
        }

        if ($layer) $user = array_replace_recursive($user, Objects::array($layer, false));

        return $user;
    }
}