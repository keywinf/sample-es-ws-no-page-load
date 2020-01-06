<?php

declare(strict_types=1);

namespace App\Infrastructure\Queuing\Messaging\EventTopic;

// .
// .
// .

final class EventTopicWebSocketProcessor implements Processor, TopicSubscriberInterface
{
    // .
    // .
    // .

    public function treat(DomainEvent $event): array
    {
        $ans = [];

        switch (true) {
            /*
             * error_log (string)
             */
            case ($event instanceof BotErrorWasRaised):
            case ($event instanceof VideoGenerationErrorWasRaised):
                $ans['error_log'] = $event->errorLog(true);
                break;

            /*
             * source_branch (string)
             */
            case ($event instanceof BotSwitchedToBranch):
                $ans['source_branch'] = $event->sourceBranch();
                break;

            /*
             * auth_key (string)
             * creator_id (string)
             * created_at (string)
             * flavor_id (string)
             * ip_address (string)
             * license_id (string)
             * os_image_id (string)
             * server_id (string)
             * snapshot_id (string)
             * source_branch (string)
             * state (string)
             * name (string)
             * license (object) {
             *      email (string)
             * }
             */
            case ($event instanceof BotWasDeployed):
                $ans = array_replace($ans, $event->toArray()['payload'], [
                    'license' => [
                        'email' => $this->licenseRepository->get($event->licenseId())->email(true),
                    ],
                ]);

                break;

            /*
             * flag (object)
             */
            case ($event instanceof BotWasRebooted):
            case ($event instanceof BotWasRemoved):
            case ($event instanceof LicenseWasRemoved):
            case ($event instanceof UserWasLocked):
            case ($event instanceof UserWasPokedForActivation):
                $ans['flag'] = $event->flag(true);
                break;
            /*
             * flag (object)
             */
            case ($event instanceof ParentTemplateWasRemoved):
                $ans['flag'] = $event->flag(true);
                $ans['organization_id'] = $this->parentTemplateRepository->get($event->parentTemplateId())->organizationId(true);
                break;

            /*
             * flag (object)
             */
            case ($event instanceof VideoWasRemoved):
                $ans['flag'] = $event->flag(true);
                $video = $this->videoRepository->get($event->videoId());
                $ans['workspace_id'] = $video->workspaceId(true);
                $ans['organization_id'] = $this->videoService->organization($video->workspaceId(), $video->childTemplateId(), $video->parentTemplateId())->id(true);
                break;

            /*
             * patch (object)
             */
            case ($event instanceof BotWasChanged):
            case ($event instanceof LicenseWasChanged):
                $ans['patch'] = $event->patch(true);
                break;

            /*
             * patch (object) {
             *      [name (string)]
             *      [config (string)]
             * }
             */
            case ($event instanceof ChildTemplateWasChanged):
                $patch = array_intersect_key($event->patch(true), array_flip([
                    'name',
                    'config',
                ]));
                $ans = array_replace($ans, [
                    'patch' => $patch
                ]);
                break;

            /*
             * patch (object) {
             *      [state (string)]
             *      [name (string)]
             *      [config (string)]
             *      [generation_cost (int)]
             *      [preview (object)]
             *      [thumbnail (object)]
             *      workspace_id (string)
             *      organization_id (string)
             * }
             */
            case ($event instanceof ParentTemplateWasChanged):
                $patch = array_intersect_key($event->patch(true), array_flip([
                    'state',
                    'name',
                    'config',
                    'generation_cost',
                    'preview',
                    'thumbnail',
                ]));
                $ans = array_replace($ans, [
                    'patch' => $patch
                ]);
                $ans['organization_id'] = $this->parentTemplateRepository->get($event->parentTemplateId())->organizationId(true);
                break;

            /*
             * organization_id (string)
             */
            case ($event instanceof ParentTemplateWasCreated):
                $ans['organization_id'] = $event->organizationId(true);
                break;

            /*
             * organization_id (string)
             * workspace_id (string)
             */
            case ($event instanceof ChildTemplateWasCreated):
                $ans['organization_id'] = $event->organizationId(true);
                $ans['workspace_id'] = $event->workspaceId(true);
                break;

            /*
             * flag (object)
             * organization_id (string)
             * workspace_id (string)
             */
            case ($event instanceof ChildTemplateWasRemoved):
                $childTemplate = $this->childTemplateRepository->get($event->childTemplateId());
                $ans['flag'] = $event->flag(true);
                $ans['organization_id'] = $childTemplate->organizationId(true);
                $ans['workspace_id'] = $childTemplate->workspaceId(true);
                break;

            /*
             * bot_id (string)
             */
            case ($event instanceof LicenseWasAttributedToBot):
                $ans['bot_id'] = $event->botId(true);
                break;

            /*
             * creator_id (string)
             * created_at (string)
             * state (string)
             * email (string)
             * password (string)
             */
            case ($event instanceof LicenseWasCreated):
                $ans = array_replace($ans, $event->toArray()['payload']);
                break;

            /*
             * notification_ids (array)
             */
            case ($event instanceof NotificationsWereRemovedFromUser):
            case ($event instanceof UserNotificationsWereMarkedAsBeingRead):
                $ans['notification_ids'] = $event->notificationIds(true);
                break;

            /*
             * organization_badge (object)
             * organization_id (string)
             */
            case ($event instanceof OrganizationBadgeWasAddedToUser):
            case ($event instanceof OrganizationBadgeWasRemovedFromUser):
                $user = $this->userRepository->get($event->userId());
                $ans['organization_badge'] = $event->organizationBadge(true);
                $ans['organization_id'] = $user->organizationId(true);
                break;

            /**
             * patch (object) {
             *      [location (string)]
             *      [logo (object)]
             *      [name (string)]
             * }
             */
            case ($event instanceof OrganizationWasChanged):
                $patch = array_intersect_key($event->patch(true), array_flip([
                    'location',
                    'logo',
                    'name',
                ]));
                $ans = array_replace($ans, [
                    'patch' => $patch
                ]);
                break;

            /*
             * amount (int)
             */
            case ($event instanceof OrganizationWasCredited):
            case ($event instanceof OrganizationWasDebited):
                $ans['amount'] = $event->amount();
                break;

            /*
             * patch (object) {
             *      [email (string)]
             *      [phone (string)]
             *      [last_login (string)]
             *      [last_logout (string)]
             *      [locale (string)]
             * }
             */
            case ($event instanceof UserAccountWasChanged):
                $patch = array_intersect_key($event->patch(true), array_flip([
                    'email',
                    'phone',
                    'last_login',
                    'last_logout',
                    'locale',
                ]));
                $ans = array_replace($ans, [
                    'patch' => $patch
                ]);
                break;

            /*
             * subject (string)
             * state (bool)
             */
            case ($event instanceof UserNotificationSettingWasChanged):
                $ans = array_replace($ans, [
                    'subject' => $event->subject(true),
                    'state' => $event->state(),
                ]);
                break;

            /*
             * patch (object) {
             *      [job (string)]
             *      [names (object)]
             *      [portrait (object)]
             * }
             */
            case ($event instanceof UserProfileWasChanged):
                $patch = array_intersect_key($event->patch(true), array_flip([
                    'job',
                    'names',
                    'portrait',
                ]));
                $ans = array_replace($ans, [
                    'patch' => $patch
                ]);
                break;

            /*
             * encoded_password (object)
             * job (string)
             * names (object)
             * phone (string)
             */
            case ($event instanceof UserWasActivated):
                $ans = array_replace($ans, [
                    'encoded_password' => $event->encodedPassword(true),
                    'job' => $event->job(),
                    'names' => $event->names(true),
                    'phone' => $event->phone(true),
                ]);
                break;

            /**
             * notification (object)
             * cursor (string)
             * exhaustive_data (?string)
             * workspace (?object) {
             *      id (string)
             *      name (string)
             * }
             */
            case ($event instanceof UserWasNotified):
                $notification = $event->notification();

                $ans = array_replace($ans, [
                    'cursor' => ArrayConnection::keyToCursor($notification->id(true)),
                    'workspace' => null,
                    'notification' => $event->notification(true),
                ]);

                if ($workspace = $this->workspaceRepository->get($notification->workspaceId()))
                    $ans['workspace'] = [
                        'id' => $workspace->id(true),
                        'name' => $workspace->name(),
                    ];

                break;

            /*
             * organization_id (string)
             */
            case ($event instanceof UserWasRegisteredByEmail):
                $ans['organization_id'] = $event->organizationId(true);
                break;

            /**
             * post_id (string)
             * state (string)
             */
            case ($event instanceof VideoSocialPostStateWasChanged):
                $ans = array_replace($ans, [
                    'post_id' => $event->postId(true),
                    'state' => $event->state(true),
                ]);
                break;

            /**
             * post_id (string)
             * by (object)
             * network (string)
             * remote_space_id (string)
             * title (string)
             * description (string)
             * scheduled_for (string)
             * state (string)
             * remote_space_access (object)
             */
            case ($event instanceof VideoSocialPostWasScheduled):
                $ans = array_replace($ans, [
                    'post_id' => $event->postId(true),
                    'by' => $event->by(true),
                    'network' => $event->network(true),
                    'remote_space_id' => $event->remoteSpaceId(),
                    'title' => $event->title(),
                    'description' => $event->description(),
                    'scheduled_for' => $event->scheduledFor(true),
                ]);

                // since event just occured, projection may not be set yet, let's wait a bit
                $this->projectionWaiter->waitForAggregate('dty.video_finder', 'id', $event->videoId(true), function ($video) use ($event) {
                    return count($video->social_posts) > 0 && Arrays::find($video->social_posts, function ($post) use ($event) {
                        return $post->id === $event->postId(true);
                    });
                });

                $queryString = <<<'GQL'
query ($id: String, $postId: String) {
    video (id: $id) {
        id
        socialPosts (id: $postId) {
            state
            remoteSpaceAccess {
                details
            }
        }
    }
}
GQL;

                $video = null;
                $this->queryBus
                    ->dispatch(Get::with($queryString, ['id' => $event->videoId(true), 'postId' => $event->postId(true)]))
                    ->done(function (?\stdClass $result = null) use (&$video) {$video = $result->video;});

                /** @var \stdClass $video */
                $post = $video->socialPosts[0];
                $ans['state'] = $post->state;
                $ans['remote_space_access'] = Objects::array($post->remoteSpaceAccess);
                break;

            /**
             * patch (object) {
             *      [file (object)]
             *      [state (string)]
             *      [name (string)]
             *      [description (string)]
             *      [template_data (string)]
             * }
             */
            case ($event instanceof VideoWasChanged):
                $patch = array_intersect_key($event->patch(true), array_flip([
                    'file',
                    'state',
                    'name',
                    'description',
                    'template_data',
                ]));
                $ans = array_replace($ans, [
                    'patch' => $patch
                ]);
                break;

            /**
             * organization_id (string)
             * workspace_id (string)
             */
            case ($event instanceof VideoWasCreated):
                $organization = $this->videoService->organization($event->workspaceId(), $event->childTemplateId(), $event->parentTemplateId());
                $ans['organization_id'] = $organization->id(true);
                $ans['workspace_id'] = $event->workspaceId(true);
                break;

            /**
             * post_id (string)
             * remote_post_id (string)
             */
            case ($event instanceof VideoWasPostedOnSocials):
                $ans = array_replace($ans, [
                     'post_id' => $event->postId(true),
                     'remote_post_id' => $event->remotePostId(),
                ]);
                break;

            /**
             * patch (object) {
             *      [name (string)]
             * }
             */
            case ($event instanceof WorkspaceWasChanged):
                $patch = array_intersect_key($event->patch(true), array_flip([
                    'name',
                ]));
                $ans = array_replace($ans, [
                    'patch' => $patch
                ]);
                break;

            /**
             * created_at (string)
             * creator_id (string)
             * organization_id (string)
             * name (string)
             */
            case ($event instanceof WorkspaceWasCreated):
                $ans = array_replace($ans, [
                    'created_at' => $event->createdAt(true),
                    'creator_id' => $event->creatorId(true),
                    'organization_id' => $event->organizationId(true),
                    'name' => $event->name(),
                ]);
                break;
        }

        return $ans;
    }

    /**
     * socket.io will be interested in that.
     * This methods gives the recipient user ids. Theses users are authorized to receive event payload in frontend
     * Returns array of user ids, empty array [] for nobody, NULL for everyone
     * In case no vote could have been made, it returns [] (nobody) by default
     *
     * @param DomainEvent $event
     * @return array|null
     * @throws \Exception
     */
    public function recipient(DomainEvent $event): ?array
    {
        switch (true) {
            // workspace sharers
            case ($event instanceof VideoSocialPostStateWasChanged):
            case ($event instanceof VideoSocialPostWasDiscarded):
            case ($event instanceof VideoSocialPostWasScheduled):
            case ($event instanceof VideoWasPostedOnSocials):
                $queryString = <<<'GQL'
query ($id: String) {
    video (id: $id) {
        workspace {
            id
            organization {
                members {
                    edges {
                        node {
                            id
                            profile {
                                organizationBadges {
                                    workspaceId
                                    badge
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
GQL;

                $video = null;
                $this->queryBus
                    ->dispatch(Get::with($queryString, ['id' => $event->videoId(true)]))
                    ->done(function (?\stdClass $result = null) use (&$video) {$video = $result->video;});

                $ans = [];
                /** @var \stdClass $video */
                if (!$video) return null;
                foreach ($video->workspace->organization->members->edges as $member) {
                    $member = new User($member->node); // Security User class is useful for methods such as isGrantedOrganizationBadge
                    if ($member->isGrantedOrganizationBadge(UserOrganizationBadge::fromArray([
                        'workspace_id' => $video->workspace->id,
                        'badge' => OrganizationBadge::SHARER,
                    ]))) $ans[] = $member->id();
                }

                return $ans;

            // creator
            case ($event instanceof BotWasDeployed):
            case ($event instanceof LicenseWasCreated):
                return [$event->creatorId(true)];

            // admins
            case ($event instanceof BotErrorWasRaised):
            case ($event instanceof BotSwitchedToBranch):
            case ($event instanceof BotWasChanged):
            case ($event instanceof BotWasRebooted):
            case ($event instanceof BotWasRemoved):
            case ($event instanceof LicenseWasAttributedToBot):
            case ($event instanceof LicenseWasChanged):
            case ($event instanceof LicenseWasRemoved):
            case ($event instanceof VideoGenerationErrorWasRaised):
            case ($event instanceof VideoWasReinitialized):
                return $this->userFinder->findUsersHavingRole(UserRole::ADMIN());

            // childTemplate organization members
            case ($event instanceof ChildTemplateWasChanged):
                $queryString = <<<'GQL'
query ($id: String) {
    childTemplate (id: $id) {
        organization {
            memberIds
        }
    }
}
GQL;

                $childTemplate = null;
                $this->queryBus
                    ->dispatch(Get::with($queryString, ['id' => $event->childTemplateId(true)]))
                    ->done(function (?\stdClass $result = null) use (&$childTemplate) {$childTemplate = $result->childTemplate;});

                /** @var \stdClass $childTemplate */
                return $childTemplate->organization->memberIds;
            /** @noinspection PhpMissingBreakStatementInspection */
            case ($event instanceof ChildTemplateWasCreated):
                $admins = [];
            // childTemplate organization members + admins
            case ($event instanceof ChildTemplateWasRemoved):
                $childTemplate = $this->childTemplateRepository->get($event->childTemplateId());
                if (!isset($admins)) $admins = $this->userFinder->findUsersHavingRole(UserRole::ADMIN());
                $queryString = <<<'GQL'
query ($id: String) {
    organization (id: $id) {
        memberIds
    }
}
GQL;

                $organization = null;
                $this->queryBus
                    ->dispatch(Get::with($queryString, ['id' => $childTemplate->organizationId(true)]))
                    ->done(function (?\stdClass $result = null) use (&$organization) {$organization = $result->organization;});

                /** @var \stdClass $organization */
                return Arrays::union($organization->memberIds, $admins);

            // parentTemplate organization members OR everyone
            /** @noinspection PhpMissingBreakStatementInspection */
            case ($event instanceof ParentTemplateWasCreated):
                $admins = [];
            // parentTemplate organization members + admins OR everyone
            case ($event instanceof ParentTemplateConfigThumbnailWasChanged):
            case ($event instanceof ParentTemplateWasChanged):
            case ($event instanceof ParentTemplateWasPruned):
            case ($event instanceof ParentTemplateWasRemoved):
                $parentTemplate = $this->parentTemplateRepository->get($event->parentTemplateId());
                if (!isset($admins)) $admins = $this->userFinder->findUsersHavingRole(UserRole::ADMIN());
                $queryString = <<<'GQL'
query ($id: String) {
    organization (id: $id) {
        memberIds
    }
}
GQL;

                if (!$organizationId = $parentTemplate->organizationId(true)) return null;
                $organization = null;
                $this->queryBus
                    ->dispatch(Get::with($queryString, ['id' => $organizationId]))
                    ->done(function (?\stdClass $result = null) use (&$organization) {$organization = $result->organization;});

                /** @var \stdClass $organization */
                return Arrays::union($organization->memberIds, $admins);

            // video organization members + admins
            /** @noinspection PhpMissingBreakStatementInspection */
            case ($event instanceof VideoWasCreated):
                $organization = $this->videoService->organization($event->workspaceId(), $event->childTemplateId(), $event->parentTemplateId());
            case ($event instanceof VideoWasRemoved):
                $video = $this->videoRepository->get($event->videoId());
                if (!isset($organization)) $organization = $this->videoService->organization($video->workspaceId(), $video->childTemplateId(), $video->parentTemplateId());
                $queryString = <<<'GQL'
query ($id: String) {
    organization (id: $id) {
        memberIds
    }
}
GQL;

                $this->queryBus
                    ->dispatch(Get::with($queryString, ['id' => $organization->id(true)]))
                    ->done(function (?\stdClass $result = null) use (&$organization) {$organization = $result->organization;});

                /** @var \stdClass $organization */
                return $organization ? Arrays::union($organization->memberIds, $this->userFinder->findUsersHavingRole(UserRole::ADMIN())) : [];
            /** @noinspection PhpMissingBreakStatementInspection */
            case ($event instanceof VideoWasChanged):
                $admins = $this->userFinder->findUsersHavingRole(UserRole::ADMIN());
            // video organization members
            case ($event instanceof VideoBecameOrphan):
            case ($event instanceof VideoWasMovedToWorkspace):
                $queryString = <<<'GQL'
query ($id: String) {
    video (id: $id) {
        organization {
            memberIds
        }
    }
}
GQL;

                $video = null;
                $this->queryBus
                    ->dispatch(Get::with($queryString, ['id' => $event->videoId(true)]))
                    ->done(function (?\stdClass $result = null) use (&$video) {$video = $result->video;});

                /** @var \stdClass $video */
                return $video->organization ? Arrays::union($video->organization->memberIds, isset($admins) ? $admins : []) : [];

            // user
            case ($event instanceof NotificationsWereRemovedFromUser):
            case ($event instanceof UserNotificationsWereMarkedAsBeingRead):
            case ($event instanceof UserWasNotified):
                return [$event->userId(true)];

            // user organization members
            case ($event instanceof OrganizationBadgeWasAddedToUser):
            case ($event instanceof OrganizationBadgeWasRemovedFromUser):
            case ($event instanceof UserAccountWasChanged):
            case ($event instanceof UserNotificationSettingWasChanged):
            case ($event instanceof UserProfileWasChanged):
            case ($event instanceof UserWasActivated):
            case ($event instanceof UserWasLocked):
            case ($event instanceof UserWasPokedForActivation):
                $queryString = <<<'GQL'
query ($id: String) {
    user (id: $id) {
        organization {
            memberIds
        }
    }
}
GQL;

                $user = null;
                $this->queryBus
                    ->dispatch(Get::with($queryString, ['id' => $event->userId(true)]))
                    ->done(function (?\stdClass $result = null) use (&$user) {$user = $result->user;});

                /** @var \stdClass $user */
                return $user->organization->memberIds;

            // workspace organization members
            case ($event instanceof WorkspaceWasChanged):
                $queryString = <<<'GQL'
query ($id: String) {
    workspace (id: $id) {
        organization {
            memberIds
        }
    }
}
GQL;

                $this->queryBus
                    ->dispatch(Get::with($queryString, ['id' => $event->workspaceId(true)]))
                    ->done(function (?\stdClass $result = null) use (&$organization) {$organization = $result->workspace->organization;});

                /** @var \stdClass $organization */
                return $organization->memberIds;

            // organization members
            /** @noinspection PhpMissingBreakStatementInspection */
            case ($event instanceof UserWasRegisteredByEmail):
                if (!$organizationId = $event->organizationId(true)) return null;
            /** @noinspection PhpMissingBreakStatementInspection */
            case ($event instanceof WorkspaceWasCreated):
                if (!isset($organizationId)) $organizationId = $event->organizationId(true);
            case ($event instanceof OrganizationWasChanged):
            case ($event instanceof OrganizationPlanWasChanged):
            case ($event instanceof OrganizationPlanWasStarted):
            case ($event instanceof OrganizationWasCredited):
            case ($event instanceof OrganizationWasDebited):
                if (!isset($organizationId)) $organizationId = $event->organizationId(true);

                $queryString = <<<'GQL'
query ($id: String) {
    organization (id: $id) {
        memberIds
    }
}
GQL;

                $this->queryBus
                    ->dispatch(Get::with($queryString, ['id' => $organizationId]))
                    ->done(function (?\stdClass $result = null) use (&$organization) {$organization = $result->organization;});

                /** @var \stdClass $organization */
                return $organization->memberIds;

            default:
                return [];
        }
    }

    // .
    // .
    // .
}