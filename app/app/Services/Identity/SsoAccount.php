<?php
namespace App\Services\Identity;
use App\Models\User;
use App\Support\Normalize;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;

/** Internal projection of a verified COM identity. No registration, attribution or CRM side effects. */
final class SsoAccount {
 public function resolve(string $subject, array $claims): User {
  if (($claims['sub'] ?? null) !== $subject || ($claims['email_verified'] ?? null) !== true
   || !is_string($claims['email'] ?? null) || strlen($claims['email'])>254
   || !filter_var($claims['email'],FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('Verified SSO profile required');
  $email=Normalize::email($claims['email']);
  for ($attempt=0;$attempt<2;$attempt++) {
   try {
    return DB::transaction(function() use($subject,$claims,$email): User {
     DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['sso:'.$subject]);
     DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['sso-email:'.$email]);
     if ($user=User::where('global_subject_id',$subject)->first()) return $user;
     $user=new User;
     $user->name=is_string($claims['name'] ?? null) && trim($claims['name'])!=='' ? mb_substr(trim($claims['name']),0,255) : 'Usuario JAKAWI';
     // Never resolve or attach an account by email. Leave email absent on collision.
     if (!User::where('email',$email)->exists()) $user->email=$email;
     $user->global_subject_id=$subject;
     $user->email_verified_at=now();
     $user->activated_at=now();
     $user->sso_provisioned_at=now();
     $user->save();
     return $user;
    });
   } catch (UniqueConstraintViolationException $e) { if ($attempt===1) throw $e; }
  }
  throw new \RuntimeException('SSO projection unavailable');
 }
}
