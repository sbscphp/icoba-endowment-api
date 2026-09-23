<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

class FaqSeeder extends Seeder
{
    // php artisan db:seed --class=FaqSeeder

    public function run(): void
    {
        $faqs = [
            [
                'title' => 'What is the Igbobi College Endowment Fund?',
                'content' => 'The Igbobi College ₦10 Billion Endowment Fund for Legacy and Transformation is an initiative of the Igbobi College Old Boys\' Association (ICOBA) to secure the long-term future of the College. Contributions are raised through campaigns that fund specific priorities, and progress on each campaign is published on this platform.',
            ],
            [
                'title' => 'Who can donate?',
                'content' => 'Anyone who wishes to support Igbobi College can donate. You can give as an ICOBA member (old boy), a corporate donor (organization, company or foundation), a friend of ICOBA, a relative of an old boy, or a wife of an old boy. You will be asked to choose the option that best describes you when donating or registering.',
            ],
            [
                'title' => 'What information is collected for each type of donor?',
                'content' => 'The details we ask for depend on how you are connected to Igbobi College. ICOBA members (old boys) provide their graduation set and the house they belonged to — Parker, Townsend, Oluwole, Aggrey or Freeman. Wives, relatives and friends of old boys can tell us the graduation set and house of the old boy they are affiliated with, so their giving is recognised alongside that set and house. Corporate donors provide their organisation\'s details and can indicate whether the organisation is Igbobian-owned; if it is, they can also give the owner\'s set and house. Affiliation details are optional and can be updated later from your profile.',
            ],
            [
                'title' => 'How do I donate?',
                'content' => 'Select "Donate Now", choose the campaign you would like to support, enter your amount and currency, and provide your details. Then choose a payment method — pay online by card, or pay by bank transfer — and complete the payment. Once your payment is confirmed you will receive a confirmation and a receipt by email.',
            ],
            [
                'title' => 'Do I need an account to donate?',
                'content' => 'No. You can donate as a guest by providing your details at checkout. However, creating an account lets you view your donation history, download receipts at any time, manage your pledges, and see your recognition tier and certificates from your dashboard.',
            ],
            [
                'title' => 'What payment methods are accepted?',
                'content' => 'You can pay online with your debit or credit card, or by direct bank transfer. Online card payments are processed through Stripe or FCMB PayGate. Stripe accepts donations in all supported currencies — Naira (NGN), US Dollars (USD), British Pounds (GBP) and Euros (EUR). FCMB PayGate is currently recommended for donations in Naira (NGN), with support for other currencies coming soon. With either option you will be redirected to the payment provider\'s secure page to enter your card details and authorise the payment, then returned to this platform where your donation is confirmed automatically. Alternatively, you can pay by direct bank transfer into the Endowment Fund\'s designated bank accounts. The payment options available to you are displayed at checkout based on your chosen currency.',
            ],
            [
                'title' => 'Which currencies can I donate in?',
                'content' => 'Donations are accepted in Nigerian Naira (NGN), US Dollars (USD), British Pounds (GBP) and Euros (EUR). Contributions made in foreign currencies are converted to their Naira equivalent for campaign progress, leaderboards and recognition tiers.',
            ],
            [
                'title' => 'How do I donate by bank transfer?',
                'content' => 'Choose "Bank Transfer" at checkout to see the Endowment Fund account details for your currency. Make the transfer from your bank, then return to the platform and confirm your payment. Your donation will be marked as completed once the transfer has been verified, and your receipt will be issued afterwards.',
            ],
            [
                'title' => 'I paid directly into the FCMB bank account outside the Endowment platform. What should I do?',
                'content' => 'If you made a transfer directly into the Endowment Fund\'s FCMB account without going through the platform, your payment will not be matched to a donation automatically. Please contact the support team at support@icobaendowment.org with your full name, the amount and date of the transfer, your transfer reference or proof of payment, and the campaign you intended to support. The team will reconcile the payment, record it against your donor profile and issue your receipt.',
            ],
            [
                'title' => 'What is a pledge, and how does it work?',
                'content' => 'A pledge is a commitment to give a stated amount over time instead of paying everything at once. When creating a pledge you choose a payment plan — a single payment on a future date, monthly instalments, quarterly instalments, or a custom schedule. You will receive reminders when an instalment is due, and each payment you make counts towards fulfilling your pledge.',
            ],
            [
                'title' => 'Can I pause or reschedule my pledge?',
                'content' => 'Yes. From the "Pledges" section of your dashboard you can pause a pledge or reschedule upcoming instalments if your circumstances change. If you need further help with a pledge, please reach out to us through the Contact page.',
            ],
            [
                'title' => 'Can I donate anonymously?',
                'content' => 'Yes. Select the "Donate anonymously" option when making a donation or pledge. Your name will not be displayed publicly on leaderboards or the recent donations list. Your details are still recorded securely so that we can issue your receipt.',
            ],
            [
                'title' => 'Will I get a receipt for my donation?',
                'content' => 'Yes. A receipt is issued for every successful donation and sent to the email address you provided. If you have an account, you can also download your receipts at any time from the "Transactions" section of your dashboard.',
            ],
            [
                'title' => 'Why are an RC number and TIN required for corporate donations?',
                'content' => 'Corporate donors are asked for their Corporate Affairs Commission (CAC) registration (RC) number and Tax Identification Number (TIN) for tax-related purposes. The RC number confirms the identity of the donating organisation, and the TIN is printed on the tax receipt issued for your donation so that the contribution can be properly documented for tax purposes, including any tax relief your organisation may be entitled to claim. Both details are stored securely and used only for issuing receipts and maintaining accurate donation records.',
            ],
            [
                'title' => 'What are recognition tiers?',
                'content' => 'Recognition tiers celebrate donors based on their cumulative contributions: Friends of Igbobi College (below ₦1 million), Bronze Contributor (₦1 million and above), Silver Supporter (₦10 million and above), Gold Benefactor (₦100 million and above), Platinum Benefactor (₦500 million and above) and the Founders/Principals\' Circle (₦1 billion and above). Your tier is updated automatically as your total giving grows, and certificates of recognition can be downloaded from your dashboard.',
            ],
            [
                'title' => 'How are donations used, and how can I track progress?',
                'content' => 'Every donation goes towards the campaign you selected. Each campaign page shows its target and the amount raised so far, and campaign update reports are published on the platform so you can follow how funds are being applied.',
            ],
            [
                'title' => 'Is my payment information secure?',
                'content' => 'Yes. Online card payments are processed by licensed, PCI-compliant payment providers. Your card details are entered directly on the payment provider\'s secure page and are never stored on our servers.',
            ],
            [
                'title' => 'My payment failed or I was debited without confirmation. What should I do?',
                'content' => 'Please do not retry immediately if you have been debited. Some payments take a few minutes to confirm, and your donation will be updated automatically once confirmation is received. If it is still not reflected after 24 hours, contact us through the Contact page with your payment reference, amount and date so we can investigate.',
            ],
            [
                'title' => 'How can I contact the Endowment Fund team?',
                'content' => 'Use the Contact page to send us a message and a member of the team will respond as soon as possible. Please include your donation or pledge reference if your enquiry relates to a specific payment.',
            ],
        ];

        foreach ($faqs as $index => $faq) {
            // firstOrCreate so re-running the seeder never overwrites edits made from the CMS.
            Faq::query()->firstOrCreate(
                ['title' => $faq['title']],
                [
                    'content' => $faq['content'],
                    'sort_order' => $index,
                    'is_active' => true,
                ]
            );
        }
    }
}
