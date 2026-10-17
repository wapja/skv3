<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

/**
 * pest-plugin-browser 5.1's in-process server reads a multipart upload in two ways Livewire's upload endpoint cannot
 * use: a part named `files[]` stays under that flat key (PHP nests it as `files` => [...]), and every byte that is no
 * valid UTF-8 becomes "?" (mb_ltrim()), which breaks any image. Added to that endpoint's middleware by the browser
 * tests that upload a file, this reads the `files[]` parts again from the raw body, byte for byte, and hands them on
 * under `files`, as PHP would. The request has converted its files already, so they go into the input, which all()
 * reads as well. The temporary copies are deleted once the endpoint has stored them.
 */
final class NestUploadedFiles
{
    private const string PART = '/\A\r\nContent-Disposition: form-data; name="files\[\]"; filename="([^"]*)"\r\n(?:Content-Type: ([^\r\n]*)\r\n)?\r\n(.*)\r\n\z/s';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (preg_match('/boundary="?([^";]+)"?/i', (string) $request->header('content-type'), $boundary) !== 1) {
            return $next($request);
        }

        $files = [];

        foreach (explode('--'.$boundary[1], $request->getContent()) as $part) {
            if (preg_match(self::PART, $part, $match) === 1) {
                $path = (string) tempnam(sys_get_temp_dir(), 'pest-upload-');
                file_put_contents($path, $match[3]);
                $files[] = new UploadedFile($path, $match[1], $match[2] === '' ? null : $match[2], null, true);
            }
        }

        if ($files !== []) {
            $request->merge(['files' => $files]);
        }

        try {
            return $next($request);
        } finally {
            foreach ($files as $file) {
                File::delete($file->getPathname());
            }
        }
    }
}
