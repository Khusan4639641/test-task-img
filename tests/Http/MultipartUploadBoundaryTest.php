<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\ExactSizePng;

final class MultipartUploadBoundaryTest extends TestCase
{
    public function test_real_http_multipart_boundary(): void
    {
        $exactPath = ExactSizePng::create(5 * 1024 * 1024);
        $oversizePath = ExactSizePng::create((5 * 1024 * 1024) + 1);
        $email = sprintf('http-boundary-%s@example.test', bin2hex(random_bytes(8)));

        try {
            [$registerStatus, $registerBody] = $this->request([
                '--request', 'POST',
                '--header', 'Accept: application/json',
                '--header', 'Content-Type: application/json',
                '--data', json_encode([
                    'name' => 'HTTP Boundary User',
                    'email' => $email,
                    'password' => 'Password123',
                    'password_confirmation' => 'Password123',
                ], JSON_THROW_ON_ERROR),
                $this->url('/api/register'),
            ]);
            self::assertSame(201, $registerStatus, $registerBody);
            $token = $this->json($registerBody)['data']['token'] ?? null;
            self::assertIsString($token);

            [$exactStatus, $exactBody] = $this->request([
                '--request', 'POST',
                '--header', 'Accept: application/json',
                '--header', 'Authorization: Bearer '.$token,
                '--form', 'image=@'.$exactPath.';filename=exact.png;type=image/png',
                $this->url('/api/images'),
            ]);
            self::assertSame(202, $exactStatus, $exactBody);
            self::assertSame(5 * 1024 * 1024, $this->json($exactBody)['data']['original_size'] ?? null);
            $uploadId = $this->json($exactBody)['data']['id'] ?? null;
            self::assertIsString($uploadId);

            [$oversizeStatus, $oversizeBody] = $this->request([
                '--request', 'POST',
                '--header', 'Accept: application/json',
                '--header', 'Authorization: Bearer '.$token,
                '--form', 'image=@'.$oversizePath.';filename=oversize.png;type=image/png',
                $this->url('/api/images'),
            ]);
            self::assertSame(422, $oversizeStatus, $oversizeBody);
            self::assertArrayHasKey('image', $this->json($oversizeBody)['errors'] ?? []);

            $this->waitUntilReady($token, $uploadId);
            [$deleteStatus] = $this->request([
                '--request', 'DELETE',
                '--header', 'Accept: application/json',
                '--header', 'Authorization: Bearer '.$token,
                $this->url('/api/images/'.$uploadId),
            ]);
            self::assertSame(204, $deleteStatus);

            [$logoutStatus] = $this->request([
                '--request', 'POST',
                '--header', 'Accept: application/json',
                '--header', 'Authorization: Bearer '.$token,
                $this->url('/api/logout'),
            ]);
            self::assertSame(200, $logoutStatus);

            $cleanup = new Process(['php', 'artisan', 'images:cleanup', '--hours=0'], '/var/www/html');
            $cleanup->run();
            self::assertTrue($cleanup->isSuccessful(), $cleanup->getErrorOutput().$cleanup->getOutput());
        } finally {
            $this->cleanupRuntimeFixtures($email);

            if (is_file($exactPath)) {
                unlink($exactPath);
            }

            if (is_file($oversizePath)) {
                unlink($oversizePath);
            }
        }
    }

    /** @param list<string> $arguments
     * @return array{int, string}
     */
    private function request(array $arguments): array
    {
        $responsePath = tempnam(sys_get_temp_dir(), 'image-api-http-');

        if ($responsePath === false) {
            throw new RuntimeException('Unable to create an HTTP response file.');
        }

        try {
            $process = new Process(array_merge([
                'curl',
                '--silent',
                '--show-error',
                '--output', $responsePath,
                '--write-out', '%{http_code}',
            ], $arguments));
            $process->setTimeout(30);
            $process->run();

            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());

            return [(int) trim($process->getOutput()), (string) file_get_contents($responsePath)];
        } finally {
            unlink($responsePath);
        }
    }

    private function waitUntilReady(string $token, string $uploadId): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            [$status, $body] = $this->request([
                '--header', 'Accept: application/json',
                '--header', 'Authorization: Bearer '.$token,
                $this->url('/api/images'),
            ]);
            self::assertSame(200, $status, $body);

            foreach ($this->json($body)['data'] ?? [] as $upload) {
                if (($upload['id'] ?? null) === $uploadId && ($upload['status'] ?? null) === 'ready') {
                    return;
                }
            }

            usleep(200_000);
        }

        self::fail('The exact-size HTTP upload did not become ready.');
    }

    private function cleanupRuntimeFixtures(string $email): void
    {
        $cleanupReferences = new Process([
            'php',
            'artisan',
            'tinker',
            '--execute',
            '$user=App\\Models\\User::query()->where("email", getenv("HTTP_TEST_EMAIL"))->first(); if($user!==null){foreach($user->imageUploads()->get() as $upload){app(App\\Actions\\Images\\DeleteImageAction::class)->execute($upload);} $user->delete();}',
        ], '/var/www/html', ['HTTP_TEST_EMAIL' => $email]);
        $cleanupReferences->run();
        self::assertTrue(
            $cleanupReferences->isSuccessful(),
            $cleanupReferences->getErrorOutput().$cleanupReferences->getOutput(),
        );

        foreach ([
            ['php', 'artisan', 'images:recover', '--minutes=0'],
            ['php', 'artisan', 'images:cleanup', '--hours=0'],
        ] as $command) {
            $cleanup = new Process($command, '/var/www/html');
            $cleanup->run();
            self::assertTrue($cleanup->isSuccessful(), $cleanup->getErrorOutput().$cleanup->getOutput());
        }
    }

    /** @return array<string, mixed> */
    private function json(string $body): array
    {
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('Expected a JSON object.');
        }

        return $decoded;
    }

    private function url(string $path): string
    {
        return rtrim((string) (getenv('IMAGE_API_HTTP_BASE_URL') ?: 'http://nginx'), '/').$path;
    }
}
