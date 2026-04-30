<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FormConfig;

class FormFieldSeeder extends Seeder
{
    public function run(): void
    {
        FormConfig::updateOrCreate(
            ['form' => 'login'],
            [
                'fields' => [
                    [
                        'name' => 'email',
                        'label' => 'Email Address',
                        'placeholder' => 'Enter your email',
                        'type' => 'email',
                        'validation' => 'required|email'
                    ],
                    [
                        'name' => 'password',
                        'label' => 'Password',
                        'placeholder' => 'Enter your password',
                        'type' => 'password',
                        'validation' => 'required'
                    ]
                ]
            ]
        );

        FormConfig::updateOrCreate(
            ['form' => 'signup'],
            [
                'fields' => [
                    [
                        'name' => 'name',
                        'label' => 'Username',
                        'placeholder' => 'Choose a username',
                        'type' => 'text',
                        'validation' => 'required|min:3|max:50'
                    ],
                    [
                        'name' => 'email',
                        'label' => 'Email Address',
                        'placeholder' => 'Enter your email',
                        'type' => 'email',
                        'validation' => 'required|email|unique:users,email'
                    ],
                    [
                        'name' => 'role',
                        'label' => 'Select Role',
                        'placeholder' => 'Choose your role',
                        'type' => 'select',
                        'validation' => 'required'
                    ],
                    [
                        'name' => 'password',
                        'label' => 'Password',
                        'placeholder' => 'Create a password',
                        'type' => 'password',
                        'validation' => 'required|min:6'
                    ],
                    [
                        'name' => 'password_confirmation',
                        'label' => 'Confirm Password',
                        'placeholder' => 'Confirm your password',
                        'type' => 'password',
                        'validation' => 'required|same:password'
                    ]
                ]
            ]
        );
    }
}
