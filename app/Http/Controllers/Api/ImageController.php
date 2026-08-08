<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Images\DeleteImageAction;
use App\Actions\Images\DownloadImageAction;
use App\Actions\Images\UploadImageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImageRequest;
use App\Http\Resources\ImageResource;
use App\Models\ImageUpload;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class ImageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return ImageResource::collection(
            $user->imageUploads()
                ->with('asset')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(15),
        );
    }

    public function store(StoreImageRequest $request, UploadImageAction $action): Response
    {
        /** @var User $user */
        $user = $request->user();
        $result = $action->execute($user, $request->file('image'), $request->metadata());

        return (new ImageResource($result['upload']))
            ->response()
            ->setStatusCode($result['accepted'] ? Response::HTTP_ACCEPTED : Response::HTTP_CREATED);
    }

    public function show(ImageUpload $image, DownloadImageAction $action): Response
    {
        Gate::authorize('view', $image);

        return $action->execute($image->loadMissing('asset'));
    }

    public function destroy(ImageUpload $image, DeleteImageAction $action): Response
    {
        Gate::authorize('delete', $image);
        $action->execute($image);

        return response()->noContent();
    }
}
