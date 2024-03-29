<?php

namespace Laravel\Sanctum;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;

class Guard
{
    /**
     * The authentication factory implementation.
     *
     * @var \Illuminate\Contracts\Auth\Factory
     */
    protected $auth;

    /**
     * The number of minutes tokens should be allowed to remain valid.
     *
     * @var int
     */
    protected $expiration;

    /**
     * The provider name.
     *
     * @var string
     */
    protected $provider;

    /**
     * Create a new guard instance.
     *
     * @param  \Illuminate\Contracts\Auth\Factory  $auth
     * @param  int  $expiration
     * @param  string  $provider
     * @return void
     */
    public function __construct(AuthFactory $auth, $expiration = null, $provider = null)
    {
        $this->auth = $auth;
        $this->expiration = $expiration;
        $this->provider = $provider;
    }

    /**
     * Retrieve the authenticated user for the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function __invoke(Request $request)
    {
        if ($user = $this->auth->guard($this->getGuard($request))->user()) {
            return $this->supportsTokens($user)
                        ? $user->withAccessToken(new TransientToken)
                        : $user;
        }

        if ($token = $request->bearerToken()) {
            $model = Sanctum::$personalAccessTokenModel;

            $accessToken = $model::findToken($token);

            if (! $accessToken ||
                ($this->expiration &&
                 $accessToken->created_at->lte(now()->subMinutes($this->expiration))) ||
                ! $this->hasValidProvider($accessToken->tokenable)) {
                return;
            }

            return $this->supportsTokens($accessToken->tokenable) ? $accessToken->tokenable->withAccessToken(
                tap($accessToken->forceFill(['last_used_at' => now()]))->save()
            ) : null;
        }
    }

    /**
     * Determine if the tokenable model supports API tokens.
     *
     * @param  mixed  $tokenable
     * @return bool
     */
    protected function supportsTokens($tokenable = null)
    {
        return $tokenable && in_array(HasApiTokens::class, class_uses_recursive(
            get_class($tokenable)
        ));
    }

    /**
     * Determine if the tokenable model matches the provider's model type.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $tokenable
     * @return bool
     */
    protected function hasValidProvider($tokenable)
    {
        if (is_null($this->provider)) {
            return true;
        }

        $model = config("auth.providers.{$this->provider}.model");

        return $tokenable instanceof $model;
    }

    /**
     * Get the appropriate guard for SPA authentication
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function getGuard(Request $request)
    {
        if (config('sanctum.guard_resolution_strategy') == 'path-based') {
            $guardMap = config('sanctum.guard_map');
    
            foreach($guardMap as $pathPattern => $usedGuard) {
                if ($request->is($pathPattern)) {
                    return $usedGuard;
                }
            }
        }

        return config('sanctum.guard');
    }
}
