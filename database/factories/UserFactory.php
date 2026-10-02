<?php
namespace Database\Factories;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
class UserFactory extends Factory{
 protected $model=User::class;
 public function definition():array{return ['name'=>fake()->name(),'mobile'=>'09'.fake()->unique()->numerify('#########'),'email'=>fake()->unique()->safeEmail(),'password'=>'password','role'=>User::ROLE_EMPLOYEE,'is_verified'=>true,'is_blocked'=>false];}
}