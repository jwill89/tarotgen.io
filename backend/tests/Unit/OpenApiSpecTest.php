<?php

namespace Tarot\Tests\Unit;

use OpenApi\Analysers\AttributeAnnotationFactory;
use OpenApi\Analysers\DocBlockAnnotationFactory;
use OpenApi\Analysers\ReflectionAnalyser;
use OpenApi\Generator;
use OpenApi\SourceFinder;
use PHPUnit\Framework\TestCase;

/**
 * Guards that the committed backend/openapi.json stays in sync with the
 * swagger-php attributes on the controllers and Structure classes.
 *
 * If this fails, someone changed a route/schema without regenerating the spec:
 * run `composer docs` and commit the updated openapi.json. This is the PHP
 * analogue of a "spec is current" CI check.
 */
final class OpenApiSpecTest extends TestCase
{
    public function testCommittedSpecMatchesTheAttributes(): void
    {
        if (!class_exists(Generator::class)) {
            self::markTestSkipped('zircote/swagger-php is not installed.');
        }

        $backend  = dirname(__DIR__, 2);
        $specPath = $backend . '/openapi.json';

        self::assertFileExists($specPath, 'openapi.json is missing — run `composer docs`.');

        // Regenerate exactly as `composer docs` does (see composer.json + bin/openapi):
        // scan api/ + includes/ for OpenAPI 3.1 attributes.
        $analyser = new ReflectionAnalyser([
            new AttributeAnnotationFactory(),
            new DocBlockAnnotationFactory(),
        ]);

        $openapi = (new Generator())
            ->setVersion('3.1.0')
            ->setAnalyser($analyser)
            ->generate(new SourceFinder([$backend . '/api', $backend . '/includes']));

        $generated = json_decode($openapi->toJson(), true);
        $committed = json_decode((string) file_get_contents($specPath), true);

        self::assertSame(
            $committed,
            $generated,
            'backend/openapi.json is out of date. Run `composer docs` and commit the result.'
        );
    }
}
