<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\AuthTokenResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class AuthController extends Controller
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$K8X5FhF0C4zBbTMrk.MK5uU9m8jZwvZ0uyHve5yCnhY.89qRUd2Ta';

    public function register(RegisterRequest $request): JsonResponse
    {
        return (new AuthTokenResource($this->registerUser($request->validated())))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): AuthTokenResource
    {
        return new AuthTokenResource($this->issueAccessToken($this->authenticatedUser(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        )));
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->revokeCurrentAccessToken($user);

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function user(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * @param  array{name: string, email: string, password: string}  $attributes
     * @return array{user: User, token: string}
     */
    private function registerUser(array $attributes): array
    {
        return DB::transaction(function () use ($attributes): array {
            return $this->issueAccessToken(User::query()->create($attributes));
        }, 3);
    }

    private function authenticatedUser(string $email, string $password): User
    {
        $user = User::query()->where('email', $email)->first();
        $passwordIsValid = Hash::check($password, $user?->password ?? self::DUMMY_PASSWORD_HASH);

        if ($user === null || ! $passwordIsValid) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return $user;
    }

    /** @return array{user: User, token: string} */
    private function issueAccessToken(User $user): array
    {
        return [
            'user' => $user,
            'token' => $user->createToken(
                'api',
                (array) config('auth.api_tokens.abilities'),
                now()->addMinutes((int) config('auth.api_tokens.expiration_minutes')),
            )->plainTextToken,
        ];
    }

    private function revokeCurrentAccessToken(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof Model) {
            $token->delete();
        }
    }
}
