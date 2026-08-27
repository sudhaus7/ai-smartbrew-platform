<?php
declare(strict_types=1);


namespace Symfony\AI\Platform\Bridge\Smartbrew;

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ModelCatalog implements ModelCatalogInterface {

    protected array $modelCache = [];

    public function __construct(
        private ?HttpClientInterface $httpClient = null,
    ) {
        if ($this->httpClient === null && isset($_ENV['SMARTBREW_API_KEY'])) {
            $this->httpClient = Factory::createHttpClient();
        }

        if ($this->httpClient === null) {
            throw new InvalidArgumentException('Smartbrew API key not found or no httpClient provided',1787847374697);
        }
    }

    public function getModel( string $modelName ): Smartbrew {

        if ($this->modelCache === []) {
            $this->fetchModels();
        }
        $payload = null;
        foreach($this->modelCache as $model) {
            if ($model['id'] === $modelName) {
                $payload = $model;
            }
        }
        if (!\is_array( $payload)) {
            throw new ModelNotFoundException( \sprintf( 'Model "%s" not found.', $modelName ) );
        }

        if(isset($payload['preset']) && $payload['preset'] === true) {
            if ( [] === $payload['info']['meta']['capabilities'] ) {
                throw new InvalidArgumentException( 'The model information could not be retrieved from the Smartbrew API. Your Smartbrew server might be too old. Try upgrade it.' );
            }
            $capabilities = [];
            $capabilities[] = Capability::INPUT_MESSAGES;
            foreach($payload['info']['meta']['capabilities'] as $capability=>$value) {
                if ($value === true) {
                     $cap = match ( $capability ) {
                        'builtin_tools' => Capability::TOOL_CALLING,
                        'thinking' => Capability::THINKING,
                        'vision' => Capability::INPUT_IMAGE,
                        //'audio' => Capability::INPUT_AUDIO,
                        //'insert' => Capability::FILL_IN_THE_MIDDLE,
                        default => null,
                    };
                     if ( $cap !== null  ) {
                         $capabilities[] = $cap;
                     }

                }
            }
        } else {
            if ( [] === $payload['ollama']['capabilities'] ) {
                throw new InvalidArgumentException( 'The model information could not be retrieved from the Smartbrew API. Your Smartbrew server might be too old. Try upgrade it.' );
            }
            $capabilities = array_map(
                //                    Capability::OUTPUT_TEXT,
            //                    Capability::OUTPUT_STREAMING,
                static fn( string $capability ): Capability => match ( $capability ) {
                    'embedding' => Capability::EMBEDDINGS,
                    'completion' => Capability::INPUT_MESSAGES,
                    'tools' => Capability::TOOL_CALLING,
                    'thinking' => Capability::THINKING,
                    'vision' => Capability::INPUT_IMAGE,
                    //'audio' => Capability::INPUT_AUDIO,
                    //'insert' => Capability::FILL_IN_THE_MIDDLE,
                    default => throw new InvalidArgumentException( \sprintf( 'The "%s" capability is not supported',
                        $capability ) ),
                },
                $payload['ollama']['capabilities'],
            );
        }

        if ( \in_array( Capability::INPUT_MESSAGES, $capabilities, true ) ) {
            $capabilities[] = Capability::OUTPUT_TEXT;
            $capabilities[] = Capability::OUTPUT_STREAMING;
        }

        if ( ! \in_array( Capability::EMBEDDINGS, $capabilities, true ) ) {
            $capabilities[] = Capability::OUTPUT_STRUCTURED;
        }

        return new Smartbrew( $modelName, $capabilities );
    }

    private function fetchModels(): void
    {
        $this->modelCache = [];
        $response = $this->httpClient->request( 'GET', 'api/models' );

        try {
            $statusCode = $response->getStatusCode();
        } catch ( TransportExceptionInterface $e ) {
            throw new RuntimeException( \sprintf( 'Cannot connect to the Smartbrew API: "%s".', $e->getMessage() ),
                previous: $e );
        }

        if ( 200 !== $statusCode ) {
            $errorMessage = $this->extractErrorMessage( $response );

            throw new RuntimeException( null !== $errorMessage ? \sprintf( 'Cannot retrieve models from the Ollama API (Status code: %d): "%s".',
                $statusCode,
                $errorMessage ) : \sprintf( 'Cannot retrieve models from the Ollama API (Status code: %d).',
                $statusCode ) );
        }

        $models = $response->toArray();

        if ( [] !== $models['data'] ) {
            $this->modelCache = $models['data'];
        }

    }

    public function getModels(): array {

        $this->fetchModels();

        return array_merge( ...array_map(
            function ( array $model ): array {
                $retrievedModel = $this->getModel( $model['id'] );

                return [
                    $retrievedModel->getName() => [
                        'class'        => Smartbrew::class,
                        'capabilities' => $retrievedModel->getCapabilities(),
                    ],
                ];
            },
            $this->modelCache,
        ) );
    }

    private function extractErrorMessage( ResponseInterface $response ): ?string {
        try {
            $content = $response->getContent( false );
        } catch ( TransportExceptionInterface ) {
            return null;
        }

        if ( '' === $content ) {
            return null;
        }

        try {
            $decoded = json_decode( $content, true, 512, \JSON_THROW_ON_ERROR );

            if ( \is_array( $decoded ) && isset( $decoded['error'] ) ) {
                return $decoded['error'];
            }
        } catch ( \JsonException ) {
            // not JSON, fall through to return raw content
        }

        return $content;
    }
}
