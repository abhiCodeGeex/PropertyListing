<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class ValidateUploadedFiles
{
    public function handle(Request $request, Closure $next): Response
    {
        $message = $this->firstUploadError($request->allFiles());

        if ($message !== null) {
            return response()->json([
                'message' => $message,
                'errors' => [
                    'file' => [$message],
                ],
            ], 422);
        }

        return $next($request);
    }

    /**
     * @param  array<mixed>  $files
     */
    private function firstUploadError(array $files): ?string
    {
        foreach ($files as $file) {
            if (is_array($file)) {
                $message = $this->firstUploadError($file);

                if ($message !== null) {
                    return $message;
                }

                continue;
            }

            if (! $file instanceof UploadedFile) {
                continue;
            }

            $error = $file->getError();

            if ($error !== UPLOAD_ERR_OK) {
                return match ($error) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the server upload limit.',
                    UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially received. Please retry the upload.',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'The server upload temporary directory is missing.',
                    UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file to temporary storage.',
                    UPLOAD_ERR_EXTENSION => 'A server extension stopped the file upload.',
                    default => 'The uploaded file is invalid.',
                };
            }

            $path = $file->getPathname();

            if ($path === '' || is_dir($path) || ! is_file($path) || ! is_readable($path)) {
                return 'The uploaded file could not be read from temporary storage. Check server upload permissions and try again.';
            }
        }

        return null;
    }
}
