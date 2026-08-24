<?php

declare(strict_types=1);

namespace Drupal\drupal_simple_voting\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * The /docs page and the OpenAPI document behind it.
 *
 * The document is built in PHP from this module's real routes rather than
 * copied into a parallel YAML file: a static copy goes stale on the first
 * new endpoint and starts lying to whoever is testing.
 */
final class DocsPage extends ControllerBase {

  public function render(): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['id' => 'voting-api-docs'],
      '#attached' => [
        'library' => ['drupal_simple_voting/swagger'],
        'drupalSettings' => [
          'votingApi' => [
            'spec' => Url::fromRoute('drupal_simple_voting.openapi')->toString(),
            'csrf' => Url::fromRoute('system.csrftoken')->toString(),
          ],
        ],
      ],
    ];
  }

  public function spec(): CacheableJsonResponse {
    $response = new CacheableJsonResponse($this->document());
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(['url.site']);
    $response->addCacheableDependency($cacheability);

    return $response;
  }

  /**
   * @return array<string, mixed>
   */
  private function document(): array {
    return [
      'openapi' => '3.1.0',
      'info' => [
        'title' => 'Simple Voting System API',
        'version' => '1.0.0',
        'description' => 'Read polls and cast votes. Built on plain controllers: '
          . 'neither the rest nor the jsonapi module is installed. '
          . 'Authentication is the Drupal session cookie, and every write carries '
          . 'the core CSRF header token from /session/token.',
      ],
      'servers' => [['url' => Url::fromRoute('<front>')->setAbsolute()->toString()]],
      'tags' => [
        ['name' => 'Polls', 'description' => 'Questions and their options.'],
        ['name' => 'Votes', 'description' => 'Casting a vote.'],
      ],
      'components' => [
        'securitySchemes' => [
          'sessionCookie' => [
            'type' => 'apiKey',
            'in' => 'cookie',
            'name' => 'SESSION',
            'description' => 'Log in at /user/login. The browser keeps the cookie.',
          ],
          'csrfToken' => [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-CSRF-Token',
            'description' => 'GET /session/token and send the value on every write.',
          ],
        ],
        'schemas' => [
          'PollSummary' => [
            'type' => 'object',
            'properties' => [
              'id' => ['type' => 'string', 'format' => 'uuid'],
              'title' => ['type' => 'string'],
              'description' => ['type' => 'string'],
              'open' => ['type' => 'boolean'],
              'reveals_totals' => ['type' => 'boolean'],
              'has_voted' => ['type' => 'boolean'],
              'created' => ['type' => 'integer'],
            ],
          ],
          'Option' => [
            'type' => 'object',
            'properties' => [
              'id' => ['type' => 'string', 'format' => 'uuid'],
              'title' => ['type' => 'string'],
              'description' => ['type' => 'string'],
              'image' => ['type' => ['string', 'null'], 'format' => 'uri'],
            ],
          ],
          'Error' => [
            'type' => 'object',
            'properties' => ['error' => ['type' => 'string']],
          ],
        ],
      ],
      'paths' => [
        '/api/v1/polls' => [
          'get' => [
            'tags' => ['Polls'],
            'summary' => 'List polls',
            'responses' => [
              '200' => [
                'description' => 'Every poll the caller may see.',
                'content' => ['application/json' => ['schema' => [
                  'type' => 'object',
                  'properties' => ['data' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/PollSummary'],
                  ]],
                ]]],
              ],
            ],
          ],
        ],
        '/api/v1/polls/{uuid}' => [
          'get' => [
            'tags' => ['Polls'],
            'summary' => 'Read one poll with its options',
            'parameters' => [$this->uuidParameter()],
            'responses' => [
              '200' => ['description' => 'The poll and its options.'],
              '404' => $this->errorResponse('No poll with that identifier.'),
            ],
          ],
        ],
        '/api/v1/polls/{uuid}/results' => [
          'get' => [
            'tags' => ['Polls'],
            'summary' => 'Read the results',
            'description' => 'Counts appear only when the poll reveals them and the '
              . 'caller has already voted. Otherwise the numbers are absent from the '
              . 'payload, not hidden by the client.',
            'parameters' => [$this->uuidParameter()],
            'responses' => [
              '200' => ['description' => 'The tally, when the caller may see it.'],
              '404' => $this->errorResponse('No poll with that identifier.'),
            ],
          ],
        ],
        '/api/v1/polls/{uuid}/vote' => [
          'post' => [
            'tags' => ['Votes'],
            'summary' => 'Cast a vote',
            'description' => 'One vote per user per poll. The database enforces it '
              . 'with a unique key on (uid, question), so a race between two '
              . 'simultaneous requests ends in 409 rather than a double vote.',
            'security' => [['sessionCookie' => [], 'csrfToken' => []]],
            'parameters' => [$this->uuidParameter()],
            'requestBody' => [
              'required' => TRUE,
              'content' => ['application/json' => [
                'schema' => [
                  'type' => 'object',
                  'required' => ['option_id'],
                  'properties' => ['option_id' => ['type' => 'string', 'format' => 'uuid']],
                ],
              ]],
            ],
            'responses' => [
              '201' => ['description' => 'Vote recorded; the payload carries the fresh results.'],
              '400' => $this->errorResponse('The body is missing option_id.'),
              '403' => $this->errorResponse('The poll is closed, or voting is off site-wide.'),
              '404' => $this->errorResponse('No poll with that identifier.'),
              '409' => $this->errorResponse('This user already voted in this poll.'),
              '422' => $this->errorResponse('That option belongs to another poll.'),
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function uuidParameter(): array {
    return [
      'name' => 'uuid',
      'in' => 'path',
      'required' => TRUE,
      'description' => 'The public identifier of the poll.',
      'schema' => ['type' => 'string', 'format' => 'uuid'],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function errorResponse(string $description): array {
    return [
      'description' => $description,
      'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
    ];
  }

}
