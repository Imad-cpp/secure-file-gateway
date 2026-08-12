<?php

namespace App\Policies;

use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class StoredFilePolicy
{
    public function view(User $user, StoredFile $file): Response
    {
        return $this->ownerResponse($user, $file);
    }

    public function delete(User $user, StoredFile $file): Response
    {
        return $this->ownerResponse($user, $file);
    }

    public function download(User $user, StoredFile $file): Response
    {
        return $this->ownerResponse($user, $file);
    }

    private function ownerResponse(User $user, StoredFile $file): Response
    {
        return $user->is($file->owner)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
