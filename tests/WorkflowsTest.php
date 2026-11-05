<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A workflow file that does not parse is not reported as a failing job: GitHub silently starts nothing.
 * This test makes that mistake fail locally, and enforces the supply chain rule that actions are pinned to a commit.
 */
final class WorkflowsTest extends TestCase
{
    private const string GITHUB = __DIR__ . '/../.github';

    /**
     * @return list<string>
     */
    private static function workflows(): array
    {
        $files = glob(self::GITHUB . '/workflows/*.yml');

        return false === $files ? [] : $files;
    }

    /**
     * @return list<string>
     */
    private static function files(): array
    {
        $files = [...self::workflows(), self::GITHUB . '/dependabot.yml'];
        self::assertGreaterThanOrEqual(3, \count($files));

        return $files;
    }

    public function testEveryWorkflowAndDependabotFileIsValidYaml(): void
    {
        foreach (self::files() as $file) {
            try {
                self::assertIsArray(Yaml::parseFile($file), $file);
            } catch (ParseException $e) {
                self::fail(\sprintf('%s is not valid YAML: %s', basename($file), $e->getMessage()));
            }
        }
    }

    public function testEveryActionIsPinnedToACommitSha(): void
    {
        foreach (self::workflows() as $file) {
            preg_match_all('/^\s*-?\s*uses:\s*(\S+)/m', (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $action) {
                self::assertMatchesRegularExpression('/@[0-9a-f]{40}$/', $action, \sprintf('%s uses %s: pin it to a commit SHA (Dependabot keeps it current)', basename($file), $action));
            }
        }
    }
}
