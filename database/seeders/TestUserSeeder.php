<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The test account, as it appears on THIS side of the estate.
 *
 * THERE IS NO PASSWORD HERE, and that is not an omission. `users` is a
 * projection of auth.v1.user.*; scheduling authenticates nobody and holds
 * nothing to check a credential against. The password for this account lives in
 * pizzasys, which is the only thing that can verify it — see that repo's own
 * TestUserSeeder.
 *
 * What this row is for is ATTRIBUTION. A token can be valid while this
 * projection is empty, and created_by_user_id / approved_by_user_id are foreign
 * keys into this table — so without the row, everything the test account does is
 * recorded against nobody. Seeding it stands in for the auth.v1.user.created
 * event that would normally put it here, and writes exactly what
 * UserCreatedHandler writes: id, name, email. Nothing else.
 *
 * THE ID MUST MATCH pizzasys BYTE FOR BYTE. Ids are assigned by auth and are the
 * same value in every service; if the two drift, the token says user 9001 and
 * this table has somebody else under that key, which mis-attributes every change
 * rather than failing visibly. Both seeders pin it explicitly for that reason.
 */
class TestUserSeeder extends Seeder
{
    /** Shared with pizzasys' TestUserSeeder. Change both or neither. */
    public const USER_ID = 9001;

    public const EMAIL = 'test@pnefoods.com';

    public function run(): void
    {
        User::query()->updateOrCreate(
            ['id' => self::USER_ID],
            [
                'name' => 'PNE Test User',
                'email' => self::EMAIL,
                // password stays NULL. See the class docblock.
            ],
        );
    }
}
