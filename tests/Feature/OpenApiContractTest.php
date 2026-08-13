<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OpenApiContractTest extends TestCase
{
    public function test_openapi_document_is_valid_and_covers_the_public_contract(): void
    {
        $path = base_path('openapi.yaml');
        $validator = (new ValidatorBuilder)
            ->fromYamlFile($path)
            ->getServerRequestValidator();

        $this->assertSame('3.0.3', $validator->getSchema()->openapi);

        $document = Yaml::parseFile($path);
        $this->assertIsArray($document);
        $this->assertArrayHasKey('paths', $document);

        $documented = [];

        foreach ($document['paths'] as $uri => $pathItem) {
            foreach (['get', 'post', 'delete'] as $method) {
                if (isset($pathItem[$method])) {
                    $documented[] = $method.' '.$uri;
                }
            }
        }

        $application = [];

        foreach (Route::getRoutes() as $route) {
            $uri = '/'.$route->uri();

            if (! str_starts_with($uri, '/api/v1/') && ! in_array($uri, ['/health/live', '/health/ready'], true)) {
                continue;
            }

            foreach ($route->methods() as $method) {
                $method = strtolower($method);

                if (in_array($method, ['get', 'post', 'delete'], true)) {
                    $application[] = $method.' '.$uri;
                }
            }
        }

        sort($documented);
        sort($application);

        $this->assertSame($application, $documented, 'OpenAPI paths/methods must match the public V1 + health routes.');
    }
}
