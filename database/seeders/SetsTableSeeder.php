<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class SetsTableSeeder extends Seeder
{
    /**
     * Graduation sets / class years for ICOBA alumni registration (set_number is validated on sign-up).
     */
    public function run(): void
    {
        $t202410 = Carbon::parse('2024-10-11 20:38:41');
        $t202504 = Carbon::parse('2025-04-15 10:57:05');

        $sets = [
            ['id' => 1, 'public_id' => '0wHeTfYjj63gwozRzS2m', 'name' => 'Set 2002', 'start_year' => null, 'end_year' => null, 'set_number' => '2002', 'created_at' => $t202410, 'updated_at' => $t202410, 'admin_id' => null],
            ['id' => 2, 'public_id' => 'WpOHzMIsUJu9soB8j1xs', 'name' => 'Set 2003', 'start_year' => null, 'end_year' => null, 'set_number' => '2003', 'created_at' => $t202410, 'updated_at' => $t202410, 'admin_id' => null],
            ['id' => 3, 'public_id' => 'Wt5Fr4oIdDkQnTqBQllM', 'name' => 'Set 2004', 'start_year' => null, 'end_year' => null, 'set_number' => '2004', 'created_at' => $t202410, 'updated_at' => $t202410, 'admin_id' => null],
            ['id' => 4, 'public_id' => 'U1iM1ofnCUHB2C6EwtbL', 'name' => 'Set 9798', 'start_year' => null, 'end_year' => null, 'set_number' => '9798', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 5, 'public_id' => '2qFlIQ7ovV6qocgkSjg5', 'name' => 'Set 7984', 'start_year' => null, 'end_year' => null, 'set_number' => '7984', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 6, 'public_id' => 'oMRBAD1rIXPTUHEjHygs', 'name' => 'Set 8789', 'start_year' => null, 'end_year' => null, 'set_number' => '8789', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 7, 'public_id' => 'cy4gK5YQADXUJuwVvx1m', 'name' => 'Set 8688', 'start_year' => null, 'end_year' => null, 'set_number' => '8688', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 8, 'public_id' => 'Go8nZF9hqJdbyM1vahhA', 'name' => 'Set 8587', 'start_year' => null, 'end_year' => null, 'set_number' => '8587', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 9, 'public_id' => 'rcrMFLPFha0HprKmJjFz', 'name' => 'Set 8486', 'start_year' => null, 'end_year' => null, 'set_number' => '8486', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 10, 'public_id' => 'VIjBwIpVSEfD3N80G0Iz', 'name' => 'Set 8385', 'start_year' => null, 'end_year' => null, 'set_number' => '8385', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 11, 'public_id' => 'qcj4Ku84BgCgMQFS7b3e', 'name' => 'Set 8284', 'start_year' => null, 'end_year' => null, 'set_number' => '8284', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 12, 'public_id' => 'ewTtKksJbshIGastak6y', 'name' => 'Set 8183', 'start_year' => null, 'end_year' => null, 'set_number' => '8183', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 13, 'public_id' => 'V7059RRmV11v5kCHMpr3', 'name' => 'Set 8085', 'start_year' => null, 'end_year' => null, 'set_number' => '8085', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 14, 'public_id' => 'PIo4Zqsf7Upg7ErLc6rt', 'name' => 'Set 8082', 'start_year' => null, 'end_year' => null, 'set_number' => '8082', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 15, 'public_id' => 'IDTGQlIALJgxEIz2B4ko', 'name' => 'Set 7981', 'start_year' => null, 'end_year' => null, 'set_number' => '7981', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 16, 'public_id' => 'oAWRWw7Vezx0BwiskPdZ', 'name' => 'Set 7880', 'start_year' => null, 'end_year' => null, 'set_number' => '7880', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 17, 'public_id' => 'l3GimeimBP1EKzXfwrDj', 'name' => 'Set 7779', 'start_year' => null, 'end_year' => null, 'set_number' => '7779', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 18, 'public_id' => 'Rlu8YS9ytDu0pEA5YGAe', 'name' => 'Set 7678', 'start_year' => null, 'end_year' => null, 'set_number' => '7678', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 19, 'public_id' => 'UPjl8WX4sZZnR6oIloxv', 'name' => 'Set 7578', 'start_year' => null, 'end_year' => null, 'set_number' => '7578', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 20, 'public_id' => 'qot2ZPY0HfnXE152ZbCv', 'name' => 'Set 7577', 'start_year' => null, 'end_year' => null, 'set_number' => '7577', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 21, 'public_id' => 'GHiZ11Xn2rn6XB7D4c8w', 'name' => 'Set 7476', 'start_year' => null, 'end_year' => null, 'set_number' => '7476', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 22, 'public_id' => '0zxV7zh2WS0YAPXBW0vq', 'name' => 'Set 7375', 'start_year' => null, 'end_year' => null, 'set_number' => '7375', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 23, 'public_id' => 'RtusBS09JEZrRevW7Jon', 'name' => 'Set 7274', 'start_year' => null, 'end_year' => null, 'set_number' => '7274', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 24, 'public_id' => 'mlR0Tq51OcmKI83M9Q0c', 'name' => 'Set 7173', 'start_year' => null, 'end_year' => null, 'set_number' => '7173', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 25, 'public_id' => 'JwqiL40Do6nDXv4msU2K', 'name' => 'Set 7072', 'start_year' => null, 'end_year' => null, 'set_number' => '7072', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 26, 'public_id' => '7G6L3MPUwDzIwz8VEPAs', 'name' => 'Set 6971', 'start_year' => null, 'end_year' => null, 'set_number' => '6971', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 27, 'public_id' => 'XusptdtKX003tcTH52fi', 'name' => 'Set 6870', 'start_year' => null, 'end_year' => null, 'set_number' => '6870', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 28, 'public_id' => 'GnzrgLez6DH3HNsFuGMa', 'name' => 'Set 6769', 'start_year' => null, 'end_year' => null, 'set_number' => '6769', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 29, 'public_id' => 'I6BVynvQDTDpkhZiXjKx', 'name' => 'Set 6668', 'start_year' => null, 'end_year' => null, 'set_number' => '6668', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 30, 'public_id' => 'z0qj0TAWZFMzO2YHnXmX', 'name' => 'Set 6567', 'start_year' => null, 'end_year' => null, 'set_number' => '6567', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 31, 'public_id' => 'uY2frz3EWFszB9SqtKAd', 'name' => 'Set 6466', 'start_year' => null, 'end_year' => null, 'set_number' => '6466', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 32, 'public_id' => 'cXnCvo6ew5GokXlkN9qU', 'name' => 'Set 6264', 'start_year' => null, 'end_year' => null, 'set_number' => '6264', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 33, 'public_id' => '1cJf9T8TRqmfrw32u8EB', 'name' => 'Set 5961', 'start_year' => null, 'end_year' => null, 'set_number' => '5961', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 34, 'public_id' => 'nGtMl86mHSpAvGP7vFZB', 'name' => 'Set 2026', 'start_year' => null, 'end_year' => null, 'set_number' => '2026', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 35, 'public_id' => 'h7SZayzurizhjB6CBEMK', 'name' => 'Set 2024', 'start_year' => null, 'end_year' => null, 'set_number' => '2024', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 36, 'public_id' => 'HHOJvCT9fzpc25BDTxyQ', 'name' => 'Set 2022', 'start_year' => null, 'end_year' => null, 'set_number' => '2022', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 37, 'public_id' => 'yeBcYjVzO1nhJz38MMkq', 'name' => 'Set 2021', 'start_year' => null, 'end_year' => null, 'set_number' => '2021', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 38, 'public_id' => '7Fq0H4BzvoLuY7r9HXcF', 'name' => 'Set 2020', 'start_year' => null, 'end_year' => null, 'set_number' => '2020', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 39, 'public_id' => 'v9fj6TGQ8UtxAt3OnVxT', 'name' => 'Set 2018', 'start_year' => null, 'end_year' => null, 'set_number' => '2018', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 40, 'public_id' => 'PII7tNAKkKDIz5SifkKZ', 'name' => 'Set 2017', 'start_year' => null, 'end_year' => null, 'set_number' => '2017', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 41, 'public_id' => 'm751dxqrOYpKVzzUmofs', 'name' => 'Set 2016', 'start_year' => null, 'end_year' => null, 'set_number' => '2016', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 42, 'public_id' => 'FQ4Z0dM14erwNOdfFcZb', 'name' => 'Set 2015', 'start_year' => null, 'end_year' => null, 'set_number' => '2015', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 43, 'public_id' => 'ulYcdCJgs8KbR7pgp2Tz', 'name' => 'Set 2014', 'start_year' => null, 'end_year' => null, 'set_number' => '2014', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 44, 'public_id' => 'Zo23xFMED0GDlrnGU9WG', 'name' => 'Set 2013', 'start_year' => null, 'end_year' => null, 'set_number' => '2013', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 45, 'public_id' => 'SS8aoLDoQ62YoOkGCryC', 'name' => 'Set 2012', 'start_year' => null, 'end_year' => null, 'set_number' => '2012', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 46, 'public_id' => 'bk9miTZliw9wHK9Mt3zB', 'name' => 'Set 2011', 'start_year' => null, 'end_year' => null, 'set_number' => '2011', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 47, 'public_id' => 'ntYAj7RsxcaOR0nZN18X', 'name' => 'Set 2010', 'start_year' => null, 'end_year' => null, 'set_number' => '2010', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 48, 'public_id' => 'W23GWfflXjMr71u2GQFN', 'name' => 'Set 2009', 'start_year' => null, 'end_year' => null, 'set_number' => '2009', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 49, 'public_id' => 'pHnx9J76wv7uWCUXA960', 'name' => 'Set 2007', 'start_year' => null, 'end_year' => null, 'set_number' => '2007', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 50, 'public_id' => 'RZeCX3iD9lBuADfkzvsI', 'name' => 'Set 2006', 'start_year' => null, 'end_year' => null, 'set_number' => '2006', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 51, 'public_id' => 'PF4lJha6lEmvDYZT6U9T', 'name' => 'Set 2005', 'start_year' => null, 'end_year' => null, 'set_number' => '2005', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 55, 'public_id' => 'CcnpfBGmyLr8f0jKQ7go', 'name' => 'Set 2001', 'start_year' => null, 'end_year' => null, 'set_number' => '2001', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 56, 'public_id' => 'CUOyBrFfhucRkpTa0tvY', 'name' => 'Set 2000', 'start_year' => null, 'end_year' => null, 'set_number' => '2000', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 57, 'public_id' => 'fDWiJEzjG1AfKd5ZPYkP', 'name' => 'Set 1999', 'start_year' => null, 'end_year' => null, 'set_number' => '1999', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 58, 'public_id' => '4n189ZZtRLYAQUKS93oV', 'name' => 'Set 1998', 'start_year' => null, 'end_year' => null, 'set_number' => '1998', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 59, 'public_id' => 'TfyjGejAWZgNFvtgHous', 'name' => 'Set 1997', 'start_year' => null, 'end_year' => null, 'set_number' => '1997', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 60, 'public_id' => 'PhWOl4J26vOG8TBvxA5j', 'name' => 'Set 1996', 'start_year' => null, 'end_year' => null, 'set_number' => '1996', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 61, 'public_id' => 'Ea3vOUz9D70zkmKRUuPi', 'name' => 'Set 1995', 'start_year' => null, 'end_year' => null, 'set_number' => '1995', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 62, 'public_id' => 'R8tDAOyTQwG1NFDvUwuO', 'name' => 'Set 1994', 'start_year' => null, 'end_year' => null, 'set_number' => '1994', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 63, 'public_id' => 'SkR26JfjymJCloYW4JOm', 'name' => 'Set 1993', 'start_year' => null, 'end_year' => null, 'set_number' => '1993', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 64, 'public_id' => 'zDTilKBm4DnNqRtbgbii', 'name' => 'Set 1992', 'start_year' => null, 'end_year' => null, 'set_number' => '1992', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 65, 'public_id' => 'jYPCDzEPX0dRvOEuBXyB', 'name' => 'Set 1991', 'start_year' => null, 'end_year' => null, 'set_number' => '1991', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 66, 'public_id' => 'BNQ3ZgcmuHUGNPlSSWRI', 'name' => 'Set 1989', 'start_year' => null, 'end_year' => null, 'set_number' => '1989', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 67, 'public_id' => 'VeHDTZ1UbHNg6kAQp1dR', 'name' => 'Set 1988', 'start_year' => null, 'end_year' => null, 'set_number' => '1988', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 68, 'public_id' => 'G08h6NQclgCicplwq13f', 'name' => 'Set 1987', 'start_year' => null, 'end_year' => null, 'set_number' => '1987', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 69, 'public_id' => 'FdCfmRHho6ASEjPhRRe4', 'name' => 'Set 1986', 'start_year' => null, 'end_year' => null, 'set_number' => '1986', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 70, 'public_id' => 'VMN0y0MdIaKDJc2Q0D5e', 'name' => 'Set 1985', 'start_year' => null, 'end_year' => null, 'set_number' => '1985', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 71, 'public_id' => 'T6deKhLOkdux2ih5srBB', 'name' => 'Set 1984', 'start_year' => null, 'end_year' => null, 'set_number' => '1984', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 72, 'public_id' => 'dXRIqGC7nToDeCh2z2Rk', 'name' => 'Set 1983', 'start_year' => null, 'end_year' => null, 'set_number' => '1983', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 73, 'public_id' => 'N4SG23q3CUyZFsIDUfcq', 'name' => 'Set 1982', 'start_year' => null, 'end_year' => null, 'set_number' => '1982', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 74, 'public_id' => 'gY4JYQ2KhPw8l0r8uhCr', 'name' => 'Set 1981', 'start_year' => null, 'end_year' => null, 'set_number' => '1981', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 75, 'public_id' => 'Czx9ErittpV884qJwUWY', 'name' => 'Set 1980', 'start_year' => null, 'end_year' => null, 'set_number' => '1980', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 76, 'public_id' => 'onSirjQrHQen8oHGPEyC', 'name' => 'Set 1979', 'start_year' => null, 'end_year' => null, 'set_number' => '1979', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 77, 'public_id' => '03GURV1XhBGwAyymE6n6', 'name' => 'Set 1978', 'start_year' => null, 'end_year' => null, 'set_number' => '1978', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 78, 'public_id' => 'Ttakvb0vFFeIfxL0fx9N', 'name' => 'Set 1977', 'start_year' => null, 'end_year' => null, 'set_number' => '1977', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 79, 'public_id' => 'FclHfmNZZvHkLgVxRESR', 'name' => 'Set 1976', 'start_year' => null, 'end_year' => null, 'set_number' => '1976', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 80, 'public_id' => 'ruAoloOXXlqOHOdJb4zc', 'name' => 'Set 1975', 'start_year' => null, 'end_year' => null, 'set_number' => '1975', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 81, 'public_id' => 'YaVvzJDUWPhMK4GZYBHs', 'name' => 'Set 1974', 'start_year' => null, 'end_year' => null, 'set_number' => '1974', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 82, 'public_id' => 'pgUmNaGrMGC78FRXGTx3', 'name' => 'Set 1972', 'start_year' => null, 'end_year' => null, 'set_number' => '1972', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 83, 'public_id' => 'vMDtlnV8KKIXZivhKdPO', 'name' => 'Set 1971', 'start_year' => null, 'end_year' => null, 'set_number' => '1971', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 84, 'public_id' => 'Wc7JQQUGllBeYOJ8Tbcw', 'name' => 'Set 1964', 'start_year' => null, 'end_year' => null, 'set_number' => '1964', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 85, 'public_id' => 'uRQge3tSOpoxdlB9MBnV', 'name' => 'Set 1960', 'start_year' => null, 'end_year' => null, 'set_number' => '1960', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 86, 'public_id' => 'Bf46zUEppsaQNcPnFmWA', 'name' => 'Set 1957', 'start_year' => null, 'end_year' => null, 'set_number' => '1957', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 87, 'public_id' => 'AWNeT8ptC9l0BLmloMeN', 'name' => 'Set 1956', 'start_year' => null, 'end_year' => null, 'set_number' => '1956', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 88, 'public_id' => 'SDeOlpOL7plUgo9mqn9V', 'name' => 'Set 1955', 'start_year' => null, 'end_year' => null, 'set_number' => '1955', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 89, 'public_id' => 'S6lnfGtj47xxs1hctgfi', 'name' => 'Set 1954', 'start_year' => null, 'end_year' => null, 'set_number' => '1954', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 90, 'public_id' => 'Zl2e7HMP3FtYsikWhIFl', 'name' => 'Set 1951', 'start_year' => null, 'end_year' => null, 'set_number' => '1951', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 91, 'public_id' => 'AKB1pHMthCO0zmbSK9PS', 'name' => 'Set 1949', 'start_year' => null, 'end_year' => null, 'set_number' => '1949', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 92, 'public_id' => '3yt6nKzl1qnlEI3xN7ZI', 'name' => 'Set 1942', 'start_year' => null, 'end_year' => null, 'set_number' => '1942', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 93, 'public_id' => '60zzXeBZpVT3pOCYX8lN', 'name' => 'Set 1940', 'start_year' => null, 'end_year' => null, 'set_number' => '1940', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 94, 'public_id' => 'e0qsBlXqhHJIYaHHf36H', 'name' => 'Set 1934', 'start_year' => null, 'end_year' => null, 'set_number' => '1934', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 95, 'public_id' => 'hxgli4Z1oxJCfpD8KX1y', 'name' => 'Set 1932', 'start_year' => null, 'end_year' => null, 'set_number' => '1932', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
            ['id' => 96, 'public_id' => '32gqundStcuy5U7kqe9u', 'name' => 'Set 1079', 'start_year' => null, 'end_year' => null, 'set_number' => '1079', 'created_at' => $t202504, 'updated_at' => $t202504, 'admin_id' => null],
        ];

        $sets = array_map(static function (array $row): array {
            unset($row['admin_id']);

            return array_merge($row, [
                'uuid' => Uuid::uuid5(Uuid::NAMESPACE_DNS, 'icoba-endowment:set:'.$row['set_number'])->toString(),
                'admin_uuid' => null,
            ]);
        }, $sets);

        DB::table('sets')->upsert(
            $sets,
            ['id'],
            ['uuid', 'public_id', 'name', 'start_year', 'end_year', 'set_number', 'updated_at', 'admin_uuid']
        );

        $this->command?->info('Sets seeded ('.count($sets).' rows).');
    }
}
