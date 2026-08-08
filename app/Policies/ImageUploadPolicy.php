<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ImageUpload;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class ImageUploadPolicy
{
    public function view(User $user, ImageUpload $upload): Response
    {
        return $user->is($upload->user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, ImageUpload $upload): Response
    {
        return $user->is($upload->user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
