<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class KenyaAdministrativeSeeder extends Seeder
{
    public function run(): void
    {
        $countryCode = 'KE';
        $depthCounty = 0;
        $depthSubcounty = 1;
        $depthWard = 2;

       // $json = Storage::get('data/kenya_coastal_divisions.json');
        $json = file_get_contents(base_path('storage/app/data/Kenya-Counties-SubCounties-and-Wards.json'));

        $data = json_decode($json, true);
      //  dd($data);

        foreach ($data as $countyName => $subcounties) {
            $countySlug = Str::slug($countyName);
            $countyPath = "{$countryCode}/{$countySlug}";

            $countyId = DB::table('administrative_divisions')->insertGetId([
                'country_code' => $countryCode,
                'type' => 'county',
                'name' => $countyName,
                'slug' => $countySlug,
                'depth' => $depthCounty,
                'path' => $countyPath,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($subcounties as $subcountyName => $wards) {
                $subSlug = Str::slug($subcountyName);
                $subPath = "{$countyPath}/{$subSlug}";

                $subId = DB::table('administrative_divisions')->insertGetId([
                    'country_code' => $countryCode,
                    'type' => 'subcounty',
                    'name' => $subcountyName,
                    'slug' => $subSlug,
                    'depth' => $depthSubcounty,
                    'path' => $subPath,
                    'parent_id' => $countyId,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($wards as $wardName) {
                    $wardSlug = Str::slug($wardName);
                    $wardPath = "{$subPath}/{$wardSlug}";

                    DB::table('administrative_divisions')->insert([
                        'country_code' => $countryCode,
                        'type' => 'ward',
                        'name' => $wardName,
                        'slug' => $wardSlug,
                        'depth' => $depthWard,
                        'path' => $wardPath,
                        'parent_id' => $subId,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
