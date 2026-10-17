<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Livewire's temporary-upload endpoint (config/livewire.php, §6.6). The kit's upload fields (the profile photo and the
 * bulk invitation list) each send one file, and Livewire's rules check each file of a request on its own: a request
 * with more than one file, or with a file anywhere but in a "files" list, is refused (422) before Livewire stores any.
 * With the per-file `max:2048` rule, one request then stores at most 2 MB.
 */
final class LimitTemporaryUploads
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $files = $request->allFiles();
        $uploads = $files['files'] ?? [];

        abort_if(count($files) > 1 || ! is_array($uploads) || count($uploads) > 1, 422);

        return $next($request);
    }
}
