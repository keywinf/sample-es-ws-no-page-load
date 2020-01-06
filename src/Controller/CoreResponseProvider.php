<?php

declare(strict_types=1);

namespace App\Controller;

// .
// .
// .

class CoreResponseProvider
{
    // .
    // .
    // .

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
             * Cache invalidation events.
             *
             * Possible values:
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
             *      ]: cacheable and cached as long these events don't occur in the current browser window/tab
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

    // .
    // .
    // .

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

    // .
    // .
    // .
}