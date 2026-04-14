<?php

namespace Database\Seeders;

use App\Models\Flow;
use Illuminate\Database\Seeder;

/**
 * Bilingual default menu: language, live order tracking (Isnaad portal API), jobs/partner static copy,
 * order-issue placeholder, manual customer care, change language.
 *
 * Re-running this seeder updates the *first* flow row (same id) with the latest default graph.
 * It does not delete or insert a second flow when one already exists.
 */
class DefaultFlowSeeder extends Seeder
{
    public function run(): void
    {
        [$nodes, $edges] = $this->buildGraph();

        $flow = Flow::query()->orderBy('id')->first();
        if ($flow) {
            $flow->update([
                'nodes_json' => $nodes,
                'edges_json' => $edges,
            ]);
        } else {
            Flow::create([
                'nodes_json' => $nodes,
                'edges_json' => $edges,
            ]);
        }
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function buildGraph(): array
    {
        $welcomeAr =
            "مرحباً بك في *إسناد*.\n"
            ."يرجى اختيار الخدمة المطلوبة من القائمة.";

        $welcomeEn =
            "Welcome to *ISNAAD*.\n"
            ."Please select the required service from the menu.";

        $partnerAr = "للتسجيل كعميل أو شريك، يرجى تعبئة النموذج:\nhttps://www.isnaad.ai/contact";
        $partnerEn = "To register as a client or partner, please complete this form:\nhttps://www.isnaad.ai/contact";

        $trackAr = "لتتبع الطلب، يرجى إرسال رقم الطلبية (أرقام فقط) في الرسالة التالية.";
        $trackEn = "To track an order, please send your order number (digits only) in the next message.";

        $jobsAr =
            "للتوظيف:\n"
            ."الموقع: https://www.isnaad.ai\n"
            ."لينكد إن: https://www.linkedin.com/company/isnaadsa/\n"
            ."نموذج التواصل: https://www.isnaad.ai/contact";
        $jobsEn =
            "Careers:\n"
            ."Website: https://www.isnaad.ai\n"
            ."LinkedIn: https://www.linkedin.com/company/isnaadsa/\n"
            ."Contact form: https://www.isnaad.ai/contact";

        $aboutAr =
            "نبذة عن *إسناد*:\n"
            ."نقدم حلولاً تقنية وتشغيلية للتجارة الإلكترونية تشمل إدارة المخزون، تجهيز الطلبات، التوصيل للعميل، وخدمات استشارية لرفع جودة العمليات وتسريع النمو.\n\n"
            ."الموقع: https://www.isnaad.ai\n"
            ."هاتف: +966 8001111905\n"
            ."لينكد إن: https://www.linkedin.com/company/isnaadsa/\n"
            ."تواصل معنا: https://www.isnaad.ai/contact";
        $aboutEn =
            "About *ISNAAD*:\n"
            ."We provide technical and operational solutions for e-commerce, including inventory management, order fulfillment, last-mile delivery, and related consultancy to improve operational quality and accelerate growth.\n\n"
            ."Website: https://www.isnaad.ai\n"
            ."Phone: +966 8001111905\n"
            ."LinkedIn: https://www.linkedin.com/company/isnaadsa/\n"
            ."Contact: https://www.isnaad.ai/contact";

        $issueSoonAr =
            "خدمة مشاكل الطلب والتوصيل قيد التطوير وستتوفر قريباً.\n"
            ."للدعم العاجل:\nhttps://www.isnaad.ai/contact";
        $issueSoonEn =
            "Order and delivery issue handling is under development and will be available soon.\n"
            ."For urgent support:\nhttps://www.isnaad.ai/contact";

        $cx = 400.0;
        $y = 60.0;
        $dy = 130.0;
        $arX = 140.0;
        $enX = 660.0;

        $nodes = [
            $this->node('start', 'start', $cx, $y, ['welcomeText' => '']),
            $this->node('pick_lang', 'interactive_menu', $cx, $y += $dy, [
                'mode' => 'buttons',
                'headerText' => '',
                'bodyText' => 'اختر اللغة | Choose your language',
                'buttonLabel' => 'View options',
                'sections' => [],
                'buttons' => [
                    ['id' => 'lang_ar', 'title' => 'العربية'],
                    ['id' => 'lang_en', 'title' => 'English'],
                ],
                'saveSelectionAs' => '',
            ]),
            $this->node('sw_ar', 'switch_language', $arX, $y += $dy, ['language' => 'AR']),
            $this->node('sw_en', 'switch_language', $enX, $y, ['language' => 'EN']),
            $this->node('welcome_ar', 'send_message', $arX, $y += $dy, ['text' => $welcomeAr]),
            $this->node('welcome_en', 'send_message', $enX, $y, ['text' => $welcomeEn]),
            $this->node('main_ar', 'interactive_menu', $arX, $y += $dy, [
                'mode' => 'list',
                'headerText' => 'القائمة',
                'bodyText' => 'اختر خياراً:',
                'buttonLabel' => 'عرض الخيارات',
                'saveSelectionAs' => '',
                'sections' => [[
                    'title' => 'خدمات',
                    'rows' => [
                        ['id' => 'opt_track', 'title' => 'تتبع طلبية أو الشحن', 'description' => ''],
                        ['id' => 'opt_jobs', 'title' => 'الانضمام — وظائف', 'description' => ''],
                        ['id' => 'opt_partner', 'title' => 'تسجيل كشريك', 'description' => ''],
                        ['id' => 'opt_issue', 'title' => 'مشكلة في طلبية', 'description' => ''],
                        ['id' => 'opt_about', 'title' => 'من نحن', 'description' => ''],
                        ['id' => 'opt_cs', 'title' => 'خدمة العملاء', 'description' => ''],
                        ['id' => 'opt_lang', 'title' => 'تغيير اللغة', 'description' => ''],
                        ['id' => 'opt_menu', 'title' => 'القائمة (رجوع)', 'description' => ''],
                    ],
                ]],
                'buttons' => [],
            ]),
            $this->node('main_en', 'interactive_menu', $enX, $y, [
                'mode' => 'list',
                'headerText' => 'Menu',
                'bodyText' => 'Choose an option:',
                'buttonLabel' => 'View options',
                'saveSelectionAs' => '',
                'sections' => [[
                    'title' => 'Services',
                    'rows' => [
                        ['id' => 'opt_track', 'title' => 'Track order / shipping', 'description' => ''],
                        ['id' => 'opt_jobs', 'title' => 'Careers', 'description' => ''],
                        ['id' => 'opt_partner', 'title' => 'Register as partner', 'description' => ''],
                        ['id' => 'opt_issue', 'title' => 'Order / delivery issue', 'description' => ''],
                        ['id' => 'opt_about', 'title' => 'About ISNAAD', 'description' => ''],
                        ['id' => 'opt_cs', 'title' => 'Customer care', 'description' => ''],
                        ['id' => 'opt_lang', 'title' => 'Change language', 'description' => ''],
                        ['id' => 'opt_menu', 'title' => 'Menu (back)', 'description' => ''],
                    ],
                ]],
                'buttons' => [],
            ]),
            $this->node('route_main', 'condition', $cx, $y += $dy, [
                'variable' => '__language',
                'operator' => '==',
                'value' => 'AR',
            ]),

            // --- Tracking: instructions → order number (digits) → Isnaad API via system_function ---
            $this->node('track_ar', 'send_message', $arX, $y += $dy, ['text' => $trackAr]),
            $this->node('track_en', 'send_message', $enX, $y, ['text' => $trackEn]),
            $this->node('track_ask_ar', 'ask_input', $arX, $y += $dy, [
                'questionText' => '',
                'variableName' => 'order_number',
                'validateType' => 'digits',
                'errorMessage' => 'يرجى إرسال رقم الطلبية بأرقام فقط.',
            ]),
            $this->node('track_ask_en', 'ask_input', $enX, $y, [
                'questionText' => '',
                'variableName' => 'order_number',
                'validateType' => 'digits',
                'errorMessage' => 'Please send the order number as digits only.',
            ]),
            $this->node('track_lookup', 'system_function', $cx, $y += $dy, [
                'functionName' => 'track_order',
                'parameters' => [],
                'saveResultVar' => '',
            ]),
            $this->node('route_track_reply', 'condition', $cx, $y += $dy, [
                'variable' => '__language',
                'operator' => '==',
                'value' => 'AR',
            ]),
            $this->node('track_reply_ar', 'send_message', $arX, $y += $dy, [
                'text' => '{{res_ar}}',
            ]),
            $this->node('track_reply_en', 'send_message', $enX, $y, [
                'text' => '{{res_en}}',
            ]),

            // --- Careers (static) ---
            $this->node('jobs_ar', 'send_message', $arX, $y += $dy, ['text' => $jobsAr]),
            $this->node('jobs_en', 'send_message', $enX, $y, ['text' => $jobsEn]),

            // --- Partner ---
            $this->node('partner_ar', 'send_message', $arX, $y += $dy, ['text' => $partnerAr]),
            $this->node('partner_en', 'send_message', $enX, $y, ['text' => $partnerEn]),

            // --- About ISNAAD ---
            $this->node('about_ar', 'send_message', $arX, $y += $dy, ['text' => $aboutAr]),
            $this->node('about_en', 'send_message', $enX, $y, ['text' => $aboutEn]),

            // --- Order / delivery issue: order ref → store id → order_missed API ---
            $this->node('issue_intro_ar', 'send_message', $arX, $y += $dy, [
                'text' => 'لإبلاغ مشكلة في الطلب، يرجى إرسال رقم المرجع في منصتك (أرقام فقط).',
            ]),
            $this->node('issue_intro_en', 'send_message', $enX, $y, [
                'text' => 'To report an order issue, please send the reference number from your platform (digits only).',
            ]),
            $this->node('issue_order_ar', 'ask_input', $arX, $y += $dy, [
                'questionText' => '',
                'variableName' => 'order_number',
                'validateType' => 'digits',
                'errorMessage' => 'يرجى إرسال رقم المرجع بأرقام فقط.',
            ]),
            $this->node('issue_order_en', 'ask_input', $enX, $y, [
                'questionText' => '',
                'variableName' => 'order_number',
                'validateType' => 'digits',
                'errorMessage' => 'Please send the reference number as digits only.',
            ]),
            $this->node('issue_store_ar', 'ask_input', $arX, $y += $dy, [
                'questionText' => 'يرجى إرسال معرّف المتجر (Store ID) في إسناد (أرقام فقط).',
                'variableName' => 'store_id',
                'validateType' => 'digits',
                'errorMessage' => 'يرجى إرسال معرّف المتجر بأرقام فقط.',
            ]),
            $this->node('issue_store_en', 'ask_input', $enX, $y, [
                'questionText' => 'Please send your ISNAAD Store ID (digits only).',
                'variableName' => 'store_id',
                'validateType' => 'digits',
                'errorMessage' => 'Please send the store ID as digits only.',
            ]),
            $this->node('issue_lookup', 'system_function', $cx, $y += $dy, [
                'functionName' => 'order_missed',
                'parameters' => [],
                'saveResultVar' => '',
            ]),
            $this->node('route_issue_reply', 'condition', $cx, $y += $dy, [
                'variable' => '__language',
                'operator' => '==',
                'value' => 'AR',
            ]),
            $this->node('issue_reply_ar', 'send_message', $arX, $y += $dy, [
                'text' => '{{res_ar}}',
            ]),
            $this->node('issue_reply_en', 'send_message', $enX, $y, [
                'text' => '{{res_en}}',
            ]),

            $this->node('goto_lang', 'loop_goto', $cx, $y += $dy, ['targetNodeId' => 'pick_lang']),
            $this->node('cs_switch', 'switch_mode', $cx, $y += $dy, [
                'mode' => 'manual',
                'autoRevertMinutes' => 0,
                // Extra resume phrases (optional); close/cancel/stop etc. are always recognized in code while in manual.
                'triggerWords' => 'close,closed,stop,cancel,stopped,end,exit,quit,resume,restart,start,menu,bot,back,انهاء,وقف,إيقاف,ايقاف,ابدأ,قائمة,القائمة,بوت,رجوع,إعادة,اعادة,إغلاق,اغلاق',
            ]),
            $this->node('route_cs_msg', 'condition', $cx, $y += $dy, [
                'variable' => '__language',
                'operator' => '==',
                'value' => 'AR',
            ]),
            $this->node('cs_msg_ar', 'send_message', $arX, $y += $dy, [
                'text' => 'تم تحويل المحادثة إلى خدمة العملاء. تم إيقاف الرد الآلي مؤقتاً.',
            ]),
            $this->node('cs_msg_en', 'send_message', $enX, $y, [
                'text' => 'You are now connected to customer care. Automation is temporarily paused.',
            ]),
            $this->node('route_hint', 'condition', $cx, $y += $dy, [
                'variable' => '__language',
                'operator' => '==',
                'value' => 'AR',
            ]),
            $this->node('hint_ar', 'send_message', $arX, $y += $dy, [
                'text' => 'يرجى اختيار خيار من القائمة.',
            ]),
            $this->node('hint_en', 'send_message', $enX, $y, [
                'text' => 'Please pick an option from the list.',
            ]),
        ];

        $edges = [
            $this->edge('e_start_pick', 'start', 'pick_lang', 'begin'),
            $this->edge('e_pl_ar', 'pick_lang', 'sw_ar', 'btn:lang_ar'),
            $this->edge('e_pl_en', 'pick_lang', 'sw_en', 'btn:lang_en'),
            $this->edge('e_sw_ar_w', 'sw_ar', 'welcome_ar', 'out'),
            $this->edge('e_sw_en_w', 'sw_en', 'welcome_en', 'out'),
            $this->edge('e_w_ar_m', 'welcome_ar', 'main_ar', 'out'),
            $this->edge('e_w_en_m', 'welcome_en', 'main_en', 'out'),
            $this->edge('e_rm_true', 'route_main', 'main_ar', 'true'),
            $this->edge('e_rm_false', 'route_main', 'main_en', 'false'),

            $this->edge('e_mar_tr', 'main_ar', 'track_ar', 'row:opt_track'),
            $this->edge('e_men_tr', 'main_en', 'track_en', 'row:opt_track'),
            $this->edge('e_tr_ar_ask', 'track_ar', 'track_ask_ar', 'out'),
            $this->edge('e_tr_en_ask', 'track_en', 'track_ask_en', 'out'),
            $this->edge('e_tr_ask_fn_ar', 'track_ask_ar', 'track_lookup', 'answer'),
            $this->edge('e_tr_ask_fn_en', 'track_ask_en', 'track_lookup', 'answer'),
            $this->edge('e_tr_fn_ok', 'track_lookup', 'route_track_reply', 'success'),
            $this->edge('e_tr_fn_err', 'track_lookup', 'route_track_reply', 'error'),
            $this->edge('e_tr_rr_ar', 'route_track_reply', 'track_reply_ar', 'true'),
            $this->edge('e_tr_rr_en', 'route_track_reply', 'track_reply_en', 'false'),
            $this->edge('e_tr_rep_ar_rm', 'track_reply_ar', 'route_main', 'out'),
            $this->edge('e_tr_rep_en_rm', 'track_reply_en', 'route_main', 'out'),

            $this->edge('e_mar_j', 'main_ar', 'jobs_ar', 'row:opt_jobs'),
            $this->edge('e_men_j', 'main_en', 'jobs_en', 'row:opt_jobs'),
            $this->edge('e_j_ar_rm', 'jobs_ar', 'route_main', 'out'),
            $this->edge('e_j_en_rm', 'jobs_en', 'route_main', 'out'),

            $this->edge('e_mar_p', 'main_ar', 'partner_ar', 'row:opt_partner'),
            $this->edge('e_men_p', 'main_en', 'partner_en', 'row:opt_partner'),
            $this->edge('e_par_ar', 'partner_ar', 'route_main', 'out'),
            $this->edge('e_par_en', 'partner_en', 'route_main', 'out'),

            $this->edge('e_mar_i', 'main_ar', 'issue_intro_ar', 'row:opt_issue'),
            $this->edge('e_men_i', 'main_en', 'issue_intro_en', 'row:opt_issue'),
            $this->edge('e_iintro_ar_ord', 'issue_intro_ar', 'issue_order_ar', 'out'),
            $this->edge('e_iintro_en_ord', 'issue_intro_en', 'issue_order_en', 'out'),
            $this->edge('e_iord_ar_store', 'issue_order_ar', 'issue_store_ar', 'answer'),
            $this->edge('e_iord_en_store', 'issue_order_en', 'issue_store_en', 'answer'),
            $this->edge('e_istore_ar_fn', 'issue_store_ar', 'issue_lookup', 'answer'),
            $this->edge('e_istore_en_fn', 'issue_store_en', 'issue_lookup', 'answer'),
            $this->edge('e_ifn_ok', 'issue_lookup', 'route_issue_reply', 'success'),
            $this->edge('e_ifn_err', 'issue_lookup', 'route_issue_reply', 'error'),
            $this->edge('e_irr_ar', 'route_issue_reply', 'issue_reply_ar', 'true'),
            $this->edge('e_irr_en', 'route_issue_reply', 'issue_reply_en', 'false'),
            $this->edge('e_irep_ar_rm', 'issue_reply_ar', 'route_main', 'out'),
            $this->edge('e_irep_en_rm', 'issue_reply_en', 'route_main', 'out'),

            $this->edge('e_mar_about', 'main_ar', 'about_ar', 'row:opt_about'),
            $this->edge('e_men_about', 'main_en', 'about_en', 'row:opt_about'),
            $this->edge('e_about_ar_rm', 'about_ar', 'route_main', 'out'),
            $this->edge('e_about_en_rm', 'about_en', 'route_main', 'out'),

            $this->edge('e_mar_cs', 'main_ar', 'cs_switch', 'row:opt_cs'),
            $this->edge('e_men_cs', 'main_en', 'cs_switch', 'row:opt_cs'),
            $this->edge('e_cs_route', 'cs_switch', 'route_cs_msg', 'out'),
            $this->edge('e_csm_t', 'route_cs_msg', 'cs_msg_ar', 'true'),
            $this->edge('e_csm_f', 'route_cs_msg', 'cs_msg_en', 'false'),

            $this->edge('e_mar_lang', 'main_ar', 'goto_lang', 'row:opt_lang'),
            $this->edge('e_men_lang', 'main_en', 'goto_lang', 'row:opt_lang'),
            $this->edge('e_mar_menu', 'main_ar', 'route_main', 'row:opt_menu'),
            $this->edge('e_men_menu', 'main_en', 'route_main', 'row:opt_menu'),
            $this->edge('e_mar_fb', 'main_ar', 'route_hint', 'fallback'),
            $this->edge('e_men_fb', 'main_en', 'route_hint', 'fallback'),
            $this->edge('e_rh_ar', 'route_hint', 'hint_ar', 'true'),
            $this->edge('e_rh_en', 'route_hint', 'hint_en', 'false'),
            $this->edge('e_ha_rm', 'hint_ar', 'route_main', 'out'),
            $this->edge('e_he_rm', 'hint_en', 'route_main', 'out'),
        ];

        return [$nodes, $edges];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function node(string $id, string $type, float $x, float $y, array $data): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'position' => ['x' => $x, 'y' => $y],
            'data' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function edge(string $id, string $source, string $target, ?string $sourceHandle): array
    {
        return [
            'id' => $id,
            'source' => $source,
            'target' => $target,
            'sourceHandle' => $sourceHandle,
            'targetHandle' => null,
        ];
    }
}
