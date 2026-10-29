<?php

namespace Database\Seeders;

use App\Actions\CreateClinicAction;
use Illuminate\Database\Seeder;

class ClinicSeeder extends Seeder
{
    public function run(CreateClinicAction $createClinicAction): void
    {
        $clinics = [
            [
                'name' => 'Al Amal Clinic',
                'slug' => 'al-amal',
                'city' => 'Casablanca',
                'phone' => '0522000001',
                'admin_name' => 'Dr. Ahmed Amrani',
                'admin_email' => 'ahmed@alamal.ma',
                'admin_password' => 'password',
            ],
            [
                'name' => 'Clinique Atlas',
                'slug' => 'clinique-atlas',
                'city' => 'Rabat',
                'phone' => '0537000002',
                'admin_name' => 'Dr. Fatima Benali',
                'admin_email' => 'fatima@atlas.ma',
                'admin_password' => 'password',
            ],
            [
                'name' => 'Cabinet Ennour',
                'slug' => 'cabinet-ennour',
                'city' => 'Marrakech',
                'phone' => '0524000003',
                'admin_name' => 'Dr. Youssef Idrissi',
                'admin_email' => 'youssef@ennour.ma',
                'admin_password' => 'password',
            ],
            [
                'name' => 'Labo Ibn Sina',
                'slug' => 'labo-ibn-sina',
                'city' => 'Fès',
                'phone' => '0535000004',
                'admin_name' => 'Dr. Salma Tazi',
                'admin_email' => 'salma@ibnsina.ma',
                'admin_password' => 'password',
            ],
        ];

        foreach ($clinics as $clinicData) {
            $createClinicAction->execute($clinicData);
        }
    }
}
