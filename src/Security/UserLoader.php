<?php

declare(strict_types=1);

namespace App\Security;

// .
// .
// .

class UserLoader
{
    // .
    // .
    // .

    public function load($key, $value)
    {
        $params = $key === 'id'
            ? [
                'query' => '$id: String',
                'user' => 'id: $id'
            ]
            : ($key === 'email'
                ? [
                    'query' => '$email: String',
                    'user' => 'email: $email'
                ]
                : [
                    'query' => '$apiKey: String',
                    'user' => 'apiKey: $apiKey'
                ]
            );

        $queryString = <<<GQL
query ({$params['query']}) {
    user ({$params['user']}) {
        id
        createdAt
        organizationId
        organization { 
            id 
            directors {
                totalCount 
            }
            workspaces (first: -1 sort: "name") { 
                edges { 
                    node { 
                        id
                        name 
                    } 
                } 
            }
            plan {
                type
                initialCredits
                credits
                endAt
            }
        }
        workspaces (first: -1 sort: "name") { 
            edges { 
                node { 
                    id
                    name 
                } 
            } 
        }
        account {
            email
            apiKey
            encodedPassword
            roles
            locale
            locked {
                authorId
                at
            }
        }
        profile {
            portrait {
                src
            }
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
        }
    }
}
GQL;

        $user = null;
        try {
            $this->queryBus
                ->dispatch(Get::with($queryString, [
                    ($key) => $value
                ]))
                ->done(function (?\stdClass $result = null) use (&$user) {
                    if (isset($result->user)) $user = $result->user;
                });
        } catch (MessageDispatchException $ex) {
//            throw $ex;
            $this->logger->critical(__METHOD__ . ': ' . $ex->getPrevious()->getMessage() . ' ' . $ex->getPrevious()->getTraceAsString(), ['exception' => $ex->getPrevious()]);
        }

        return $user;
    }
}
