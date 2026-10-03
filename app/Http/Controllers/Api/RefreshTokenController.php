<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class RefreshTokenController extends Controller
{
    public function __construct(
        private JwtService $jwt
    ) {}

    public function __invoke(Request $request)
    {
        $refreshToken = trim(
            (string) $request->input(
                'refresh_token',
                ''
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if ($refreshToken === '') {
            return $this->error(
                'Refresh token is required',
                422
            );
        }

        $tokenHash = hash(
            'sha256',
            $refreshToken
        );

        DB::beginTransaction();

        try {
            /*
            |--------------------------------------------------------------------------
            | Find Refresh Token
            |--------------------------------------------------------------------------
            |
            | lockForUpdate prevents two simultaneous refresh requests
            | from rotating the same token.
            |
            */

            $record =
                DB::table('refresh_tokens as rt')
                    ->join(
                        'users as u',
                        'u.id',
                        '=',
                        'rt.user_id'
                    )
                    ->where(
                        'rt.token_hash',
                        $tokenHash
                    )
                    ->where(
                        'rt.is_revoked',
                        0
                    )
                    ->where(
                        'rt.expires_at',
                        '>',
                        now()
                    )
                    ->where(
                        'u.status',
                        'active'
                    )
                    ->whereNull(
                        'u.deleted_at'
                    )
                    ->select([
                        'rt.id',
                        'rt.user_id',
                        'rt.device_id',
                        'rt.expires_at',
                        'u.email',
                    ])
                    ->lockForUpdate()
                    ->first();

            /*
            |--------------------------------------------------------------------------
            | Invalid Token
            |--------------------------------------------------------------------------
            */

            if (!$record) {
                DB::rollBack();

                return $this->error(
                    'Invalid or expired refresh token',
                    401
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Preserve Original Session Expiry
            |--------------------------------------------------------------------------
            |
            | Important:
            |
            | Normal login = original maximum lifetime of 7 days.
            | Remember Me = original maximum lifetime of 30 days.
            |
            | Refreshing does NOT reset the clock.
            |
            */

            $originalExpiresAt =
                $record->expires_at;

            /*
            |--------------------------------------------------------------------------
            | Revoke Active Tokens For Session
            |--------------------------------------------------------------------------
            |
            | One device must have only one active refresh token.
            |
            | This also cleans any historical duplicates that may already exist.
            |
            */

            if ($record->device_id !== null) {
                DB::table('refresh_tokens')
                    ->where(
                        'user_id',
                        $record->user_id
                    )
                    ->where(
                        'device_id',
                        $record->device_id
                    )
                    ->where(
                        'is_revoked',
                        0
                    )
                    ->update([
                        'is_revoked' => 1,
                    ]);

            } else {
                /*
                 * Anonymous/no-device session.
                 */
                DB::table('refresh_tokens')
                    ->where(
                        'user_id',
                        $record->user_id
                    )
                    ->whereNull(
                        'device_id'
                    )
                    ->where(
                        'is_revoked',
                        0
                    )
                    ->update([
                        'is_revoked' => 1,
                    ]);
            }

            /*
            |--------------------------------------------------------------------------
            | New Refresh Token
            |--------------------------------------------------------------------------
            */

            $newRefreshToken =
                $this->jwt->refreshToken();

            DB::table('refresh_tokens')
                ->insert([
                    'user_id' =>
                        $record->user_id,

                    'device_id' =>
                        $record->device_id,

                    'token_hash' =>
                        hash(
                            'sha256',
                            $newRefreshToken
                        ),

                    'is_revoked' =>
                        0,

                    /*
                     * Keep the original expiry.
                     */
                    'expires_at' =>
                        $originalExpiresAt,
                ]);

            /*
            |--------------------------------------------------------------------------
            | New Access Token
            |--------------------------------------------------------------------------
            */

            $newAccessToken =
                $this->jwt->accessToken(
                    (int) $record->user_id,
                    $record->email
                );

            /*
            |--------------------------------------------------------------------------
            | Update Device Activity
            |--------------------------------------------------------------------------
            */

            if ($record->device_id !== null) {
                DB::table('devices')
                    ->where(
                        'id',
                        $record->device_id
                    )
                    ->where(
                        'user_id',
                        $record->user_id
                    )
                    ->update([
                        'updated_at' =>
                            now(),
                    ]);
            }

            DB::commit();

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' =>
                    true,

                'access_token' =>
                    $newAccessToken,

                'refresh_token' =>
                    $newRefreshToken,

                'token_type' =>
                    'Bearer',

                'expires_in' =>
                    900,

                'refresh_expires_at' =>
                    $originalExpiresAt,

                'device_id' =>
                    $record->device_id,
            ], 200);

        } catch (Throwable $e) {
            if (
                DB::transactionLevel() > 0
            ) {
                DB::rollBack();
            }

            report($e);

            return response()->json([
                'success' =>
                    false,

                'message' =>
                    'Could not refresh token',

                'details' =>
                    app()->environment('local')
                        ? $e->getMessage()
                        : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Standard Error
    |--------------------------------------------------------------------------
    */

    private function error(
        string $message,
        int $status
    ) {
        return response()->json([
            'success' =>
                false,

            'message' =>
                $message,
        ], $status);
    }
}