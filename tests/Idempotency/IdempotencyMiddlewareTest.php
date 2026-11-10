<?php

declare(strict_types=1);

namespace Slimfony\Core\Tests\Idempotency;

use PHPUnit\Framework\TestCase;
use Slimfony\Core\Auth\Principal;
use Slimfony\Core\Http\Attributes;
use Slimfony\Core\Idempotency\DbalIdempotencyStore;
use Slimfony\Core\Idempotency\IdempotencyMiddleware;
use Slimfony\Core\Tests\Support\Attempts;
use Slimfony\Core\Tests\Support\TestDb;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class IdempotencyMiddlewareTest extends TestCase
{
    private int $clock = 1_000_000;
    private IdempotencyMiddleware $middleware;
    private DbalIdempotencyStore $store;

    protected function setUp(): void
    {
        $connection = TestDb::connection();
        $connection->executeStatement('DROP TABLE IF EXISTS idempotency_keys');
        $connection->executeStatement(DbalIdempotencyStore::TABLE_SQL);
        $this->store = new DbalIdempotencyStore($connection, ttlSeconds: 3600, lockSeconds: 60, now: fn(): int => $this->clock);
        $this->middleware = new IdempotencyMiddleware($this->store);
    }

    private static function request(string $body = '{"amount":10}', string $key = 'order-key-0001', string $caller = 'alice', string $method = 'POST'): Request
    {
        $request = Request::create('/orders', $method, content: $body, server: ['HTTP_IDEMPOTENCY_KEY' => $key, 'REMOTE_ADDR' => '203.0.113.5']);
        $request->attributes->set(Attributes::IDEMPOTENT, true);
        $request->attributes->set(Attributes::PRINCIPAL, new Principal($caller));

        return $request;
    }

    /** @return \Closure(Request): Response a handler that counts how often it ran and answers with the given status */
    private static function handler(Attempts $ran, int $status = 201): \Closure
    {
        return static function (Request $request) use ($ran, $status): Response {
            ++$ran->n;

            return new Response('{"order":' . $ran->n . '}', $status, ['Content-Type' => 'application/json']);
        };
    }

    public function testTheSameRequestWithTheSameKeyRunsOnceAndTheSecondGetsTheFirstAnswer(): void
    {
        $ran = new Attempts();

        $first = $this->middleware->process(self::request(), self::handler($ran));
        $second = $this->middleware->process(self::request(), self::handler($ran));

        self::assertSame(1, $ran->n, 'the work was done once');
        self::assertSame(201, $second->getStatusCode());
        self::assertSame('{"order":1}', $second->getContent());
        self::assertSame('application/json', $second->headers->get('Content-Type'));
        self::assertSame('true', $second->headers->get('Idempotent-Replayed'));
        self::assertNull($first->headers->get('Idempotent-Replayed'));
    }

    public function testTheSameKeyWithADifferentRequestIsAMistake(): void
    {
        $ran = new Attempts();
        $this->middleware->process(self::request('{"amount":10}'), self::handler($ran));

        $response = $this->middleware->process(self::request('{"amount":9999}'), self::handler($ran));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(1, $ran->n);
    }

    public function testARequestThatIsStillBeingServedIsAskedToWait(): void
    {
        $ran = new Attempts();
        $inner = null;
        $outer = $this->middleware->process(self::request(), function (Request $request) use (&$inner, $ran): Response {
            $inner = $this->middleware->process(self::request(), self::handler($ran)); // the same request arrives while the first runs

            return new Response('first', 201);
        });

        self::assertSame(201, $outer->getStatusCode());
        self::assertInstanceOf(Response::class, $inner);
        self::assertSame(409, $inner->getStatusCode());
        self::assertSame('1', $inner->headers->get('Retry-After'));
        self::assertSame(0, $ran->n);
    }

    public function testAServerErrorOrAnExceptionIsNotKeptSoTheClientCanTryAgain(): void
    {
        $ran = new Attempts();
        $this->middleware->process(self::request(), self::handler($ran, 503));
        $this->middleware->process(self::request(), self::handler($ran, 503));
        self::assertSame(2, $ran->n, 'a 5xx is not an answer to keep');

        try {
            $this->middleware->process(self::request(key: 'order-key-0002'), static function (): never {
                throw new \RuntimeException('boom');
            });
            self::fail('the exception must go on');
        } catch (\RuntimeException) {
        }
        $this->middleware->process(self::request(key: 'order-key-0002'), self::handler($ran));
        self::assertSame(3, $ran->n, 'after an exception the same key runs again');
    }

    public function testAnErrorOfTheCallerIsKeptLikeAnyAnswer(): void
    {
        $ran = new Attempts();
        $this->middleware->process(self::request(), self::handler($ran, 422));

        $second = $this->middleware->process(self::request(), self::handler($ran, 201));

        self::assertSame(422, $second->getStatusCode(), 'the answer to the first request, whatever it was');
        self::assertSame(1, $ran->n);
    }

    public function testKeysBelongToTheCaller(): void
    {
        $ran = new Attempts();

        $this->middleware->process(self::request(caller: 'alice'), self::handler($ran));
        $other = $this->middleware->process(self::request(caller: 'bob'), self::handler($ran));

        self::assertSame(2, $ran->n, 'the same key of another caller is another key');
        self::assertSame(201, $other->getStatusCode());
        self::assertNull($other->headers->get('Idempotent-Replayed'));
    }

    public function testAnAnswerIsKeptForTheTtlAndAnAbandonedClaimIsTakenOver(): void
    {
        $ran = new Attempts();
        $this->middleware->process(self::request(), self::handler($ran));
        $this->clock += 3601; // the kept answer has expired
        $this->middleware->process(self::request(), self::handler($ran));
        self::assertSame(2, $ran->n);

        // a worker claimed a key and died: nobody completes it
        $this->store->begin(hash('sha256', "sub:alice\norder-key-dead00001"), 'whatever');
        $this->clock += 61;
        $this->middleware->process(self::request(key: 'order-key-dead00001'), self::handler($ran));
        self::assertSame(3, $ran->n, 'after the lock time the key is free again');
    }

    public function testRoutesAndRequestsThatDidNotAskForItRunAsUsual(): void
    {
        $ran = new Attempts();

        $plain = Request::create('/orders', 'POST', content: 'x', server: ['HTTP_IDEMPOTENCY_KEY' => 'order-key-0001']); // the route is not marked
        $this->middleware->process($plain, self::handler($ran));
        $this->middleware->process($plain, self::handler($ran));

        $withoutKey = self::request();
        $withoutKey->headers->remove('Idempotency-Key');
        $this->middleware->process($withoutKey, self::handler($ran));
        $this->middleware->process($withoutKey, self::handler($ran));

        $this->middleware->process(self::request(method: 'DELETE'), self::handler($ran)); // a method that repeats safely
        $this->middleware->process(self::request(method: 'DELETE'), self::handler($ran));

        self::assertSame(6, $ran->n);
    }

    public function testABadKeyIsRefused(): void
    {
        $ran = new Attempts();

        $response = $this->middleware->process(self::request(key: "short\ninjected"), self::handler($ran));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $ran->n);
    }

    public function testTheHandlerCanReadTheScopedKeyToGuardItsOwnEffect(): void
    {
        $seen = new Attempts();
        $keys = [];
        $this->middleware->process(self::request(), static function (Request $request) use (&$keys, $seen): Response {
            ++$seen->n;
            $key = $request->attributes->get(Attributes::IDEMPOTENCY_KEY);
            $keys[] = \is_string($key) ? $key : '';

            return new Response('ok', 201);
        });

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $keys[0], 'a hash: the caller and the key, not the raw header');
    }
}
