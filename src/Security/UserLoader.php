<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Bridge\Query\Get;
use Escqrs\ServiceBus\Exception\MessageDispatchException;
use Escqrs\ServiceBus\QueryBus;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Monolog\Logger;

class UserLoader
{
    /** @var QueryBus */
    protected $queryBus;

    /** @var Logger */
    protected $logger;

    /**
     * UserLoader constructor.
     * @param QueryBus $queryBus
     * @param LoggerInterface $logger
     */
    public function __construct(
        QueryBus $queryBus,
        LoggerInterface $logger
    )
    {
        $this->queryBus = $queryBus;
        $this->logger = $logger;
    }

    /**
     * @param string $email
     * @return User|null
     * @throws \Exception
     */
    public function loadUserByEmail(string $email)
    {
        if (! $email) return null;

        $user = $this->load('email', $email);

        if(! $user) return null;

        return new User($user);
    }

    /**
     * @param string $apiKey
     * @return User|null
     * @throws \Exception
     */
    public function loadUserByApiKey(string $apiKey)
    {
        if (! $apiKey) return null;

        $user = $this->load('apiKey', $apiKey);

        if(! $user) return null;

        return new User($user);
    }

    /**
     * @param string $userId
     * @return User|null
     * @throws \Exception
     */
    public function loadUserById(string $userId)
    {
        if (! $userId) return null;

        $user = $this->load('id', $userId);

        if(! $user) return null;

        return new User($user);
    }

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
