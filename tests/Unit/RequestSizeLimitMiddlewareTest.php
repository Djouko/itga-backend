<?php

namespace Tests\Unit;

use App\Http\Middleware\RequestSizeLimit;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Tests\TestCase;

class RequestSizeLimitMiddlewareTest extends TestCase
{
    public function test_it_rejects_requests_above_the_configured_limit(): void
    {
        $request = Request::create('/api/uploadFile', 'POST', [], [], [], [
            'CONTENT_LENGTH' => (string) (9 * 1024 * 1024),
        ]);

        $response = (new RequestSizeLimit())->handle($request, function () {
            return response()->json(['status' => true]);
        }, 8);

        $this->assertSame(413, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['status']);
    }

    public function test_it_allows_requests_under_the_configured_limit(): void
    {
        $request = Request::create('/api/uploadFile', 'POST', [], [], [], [
            'CONTENT_LENGTH' => (string) (7 * 1024 * 1024),
        ]);

        $response = (new RequestSizeLimit())->handle($request, function () {
            return response()->json(['status' => true]);
        }, 8);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['status']);
    }

    public function test_it_rejects_uploaded_files_above_the_limit_without_content_length(): void
    {
        $request = Request::create('/api/uploadFile', 'POST');
        $request->files->set('uploadFile', UploadedFile::fake()->create('portfolio.pdf', 9 * 1024));

        $response = (new RequestSizeLimit())->handle($request, function () {
            return response()->json(['status' => true]);
        }, 8);

        $this->assertSame(413, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['status']);
    }

    public function test_it_counts_nested_uploaded_files(): void
    {
        $request = Request::create('/api/addPost', 'POST');
        $request->files->set('content', [
            UploadedFile::fake()->create('clip-one.mp4', 5 * 1024),
            UploadedFile::fake()->create('clip-two.mp4', 4 * 1024),
        ]);

        $response = (new RequestSizeLimit())->handle($request, function () {
            return response()->json(['status' => true]);
        }, 8);

        $this->assertSame(413, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['status']);
    }
}
