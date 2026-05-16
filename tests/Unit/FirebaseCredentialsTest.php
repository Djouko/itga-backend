<?php

namespace Tests\Unit;

use App\Helpers\FirebaseCredentials;
use Tests\TestCase;

class FirebaseCredentialsTest extends TestCase
{
    private ?string $oldGoogleApplicationCredentials;
    private ?string $oldGoogleCredentialsPath;
    private ?string $tempCredentialsPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oldGoogleApplicationCredentials = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: null;
        $this->oldGoogleCredentialsPath = getenv('GOOGLE_CREDENTIALS_PATH') ?: null;
    }

    protected function tearDown(): void
    {
        if ($this->tempCredentialsPath && file_exists($this->tempCredentialsPath)) {
            @unlink($this->tempCredentialsPath);
        }

        $this->setEnv('GOOGLE_APPLICATION_CREDENTIALS', $this->oldGoogleApplicationCredentials);
        $this->setEnv('GOOGLE_CREDENTIALS_PATH', $this->oldGoogleCredentialsPath);

        parent::tearDown();
    }

    public function test_returns_null_when_explicit_credentials_path_is_missing()
    {
        $this->setEnv('GOOGLE_APPLICATION_CREDENTIALS', 'definitely/missing/service-account.json');
        $this->setEnv('GOOGLE_CREDENTIALS_PATH', null);

        $this->assertNull(FirebaseCredentials::getPath());
    }

    public function test_reads_project_id_from_explicit_credentials_path()
    {
        $this->tempCredentialsPath = tempnam(sys_get_temp_dir(), 'itga-fcm-') . '.json';
        file_put_contents($this->tempCredentialsPath, json_encode([
            'project_id' => 'itga-test-project',
        ]));

        $this->setEnv('GOOGLE_APPLICATION_CREDENTIALS', null);
        $this->setEnv('GOOGLE_CREDENTIALS_PATH', $this->tempCredentialsPath);

        $this->assertSame($this->tempCredentialsPath, FirebaseCredentials::getPath());
        $this->assertSame('itga-test-project', FirebaseCredentials::getProjectId());
    }

    public function test_returns_null_for_invalid_credentials_json()
    {
        $this->tempCredentialsPath = tempnam(sys_get_temp_dir(), 'itga-fcm-') . '.json';
        file_put_contents($this->tempCredentialsPath, '{invalid-json');

        $this->setEnv('GOOGLE_APPLICATION_CREDENTIALS', null);
        $this->setEnv('GOOGLE_CREDENTIALS_PATH', $this->tempCredentialsPath);

        $this->assertNull(FirebaseCredentials::getProjectId());
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
            return;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
