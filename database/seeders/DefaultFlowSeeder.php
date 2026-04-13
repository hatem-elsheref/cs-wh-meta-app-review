<?php

namespace Database\Seeders;

use App\Models\Flow;
use Illuminate\Database\Seeder;

/**
 * Bilingual default menu: language, tracking/jobs (static), partner signup link, delivery issues,
 * manual customer care, change language.
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
            "\u{1F44B} *مرحباً بك في منصتنا اللوجستية!*\n\n"
            ."أهلاً وسهلاً — نحن منصة لوجستية متكاملة نخدم مئات العلامات التجارية.\n\n"
            ."كيف يمكنني مساعدتك اليوم\u{1F4AC} اختر من القائمة أدناه.\n\n"
            ."─────────────────\n"
            ."\u{1F4AC} خدمة العملاء أو تغيير اللغة من القائمة.\n"
            ."0\u{FE0F}\u{20E3} للعودة إلى القائمة في أي وقت.";

        $welcomeEn =
            "\u{1F44B} *Welcome to our logistics platform!*\n\n"
            ."Hello — we are an integrated logistics platform serving many brands.\n\n"
            ."How can we help today\u{1F4AC} pick an option below.\n\n"
            ."─────────────────\n"
            ."\u{1F4AC} Customer care or change language from the list.\n"
            ."0\u{FE0F}\u{20E3} Return to the menu anytime.";

        $partnerAr = "للتسجيل كعميل أو شريك،يرجى تعبئة نموذج التواصل على الرابط:\nhttps://www.isnaad.ai/contact\n\nفريقنا سيتواصل معك قريباً.";
        $partnerEn = "To register as a client or partner, please complete the form here:\nhttps://www.isnaad.ai/contact\n\nOur team will get back to you shortly.";

        $trackAr = "لتتبع طلبية:\n\n- أرسل رقم الطلبية أو رقم الشحنة في *الرسالة التالية* (نص عادي)\n- بعدها ستظهر لك القائمة مرة أخرى إذا احتجت خدمة أخرى\n\n(سيتم تطوير التتبع الآلي لاحقاً)";
        $trackEn = "To track a shipment:\n\n- Send your order or tracking number in your *next message* (plain text)\n- The menu will return afterward if you need another service\n\n(Auto-tracking will be added later.)";

        $jobsAr = "الانضمام لفريقنا:\n\n• الموقع: https://www.isnaad.ai\n• الوظائف: https://www.isnaad.ai\n• تواصل معنا: https://www.isnaad.ai/contact\n• لينكد إن: https://www.linkedin.com/company/isnaad\n• الهاتف: +966 8001111905\n• البريد: hello@isnaad.ai\n\n(سيتم تطوير نموذج التقديم داخل الواتساب لاحقاً)";
        $jobsEn = "Careers:\n\n• Website: https://www.isnaad.ai\n• Careers: https://www.isnaad.ai\n• Contact form: https://www.isnaad.ai/contact\n• LinkedIn: https://www.linkedin.com/company/isnaad\n• Phone: +966 8001111905\n• Email: hello@isnaad.ai\n\n(WhatsApp application flow will be added later.)";

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

            // --- Tracking (static): instructions, then wait for reference before main menu ---
            $this->node('track_ar', 'send_message', $arX, $y += $dy, ['text' => $trackAr]),
            $this->node('track_en', 'send_message', $enX, $y, ['text' => $trackEn]),
            $this->node('track_ask_ar', 'ask_input', $arX, $y += $dy, [
                'questionText' => '',
                'variableName' => 'order_number',
                'validateType' => 'any',
                'errorMessage' => 'يرجى إرسال رقم الطلبية أو التتبع كنص (لا يمكن أن يكون فارغاً).',
            ]),
            $this->node('track_ask_en', 'ask_input', $enX, $y, [
                'questionText' => '',
                'variableName' => 'order_number',
                'validateType' => 'any',
                'errorMessage' => 'Please send an order or tracking reference (cannot be empty).',
            ]),
            $this->node('track_thanks_ar', 'send_message', $arX, $y += $dy, [
                'text' => 'تم استلام المرجع. سنتواصل معك بعد المراجعة.',
            ]),
            $this->node('track_thanks_en', 'send_message', $enX, $y, [
                'text' => 'Thanks — we received your reference. We will follow up after review.',
            ]),

            // --- Careers (static) ---
            $this->node('jobs_ar', 'send_message', $arX, $y += $dy, ['text' => $jobsAr]),
            $this->node('jobs_en', 'send_message', $enX, $y, ['text' => $jobsEn]),

            // --- Partner ---
            $this->node('partner_ar', 'send_message', $arX, $y += $dy, ['text' => $partnerAr]),
            $this->node('partner_en', 'send_message', $enX, $y, ['text' => $partnerEn]),

            // --- Order / delivery issue (placeholder until dedicated flow is built) ---
            $issueSoonAr =
                "خدمة *مشاكل الطلب والتوصيل* قيد التطوير حالياً وسيتم تفعيلها قريباً.\n\n"
                ."نعمل على تحسين هذه الخدمة لتقديم تجربة أفضل.\n\n"
                ."للمساعدة العاجلة، يمكنك التواصل معنا عبر:\nhttps://www.isnaad.ai/contact";
            $issueSoonEn =
                "*Order and delivery issues* are not fully automated yet — we are enhancing this service and it will be available soon.\n\n"
                ."Thank you for your patience.\n\n"
                ."For urgent help, please contact us:\nhttps://www.isnaad.ai/contact";
            $this->node('issue_ar', 'send_message', $arX, $y += $dy, ['text' => $issueSoonAr]),
            $this->node('issue_en', 'send_message', $enX, $y, ['text' => $issueSoonEn]),

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
                'text' => 'تم تحويل المحادثة إلى وضع *خدمة العملاء* (الرد الآلي متوقف). سيقوم أحد الزملاء بالرد قريباً.',
            ]),
            $this->node('cs_msg_en', 'send_message', $enX, $y, [
                'text' => 'You are now connected to *customer care* (automation paused). A teammate will reply shortly.',
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
            $this->edge('e_tr_ask_th_ar', 'track_ask_ar', 'track_thanks_ar', 'answer'),
            $this->edge('e_tr_ask_th_en', 'track_ask_en', 'track_thanks_en', 'answer'),
            $this->edge('e_tr_th_ar_rm', 'track_thanks_ar', 'route_main', 'out'),
            $this->edge('e_tr_th_en_rm', 'track_thanks_en', 'route_main', 'out'),

            $this->edge('e_mar_j', 'main_ar', 'jobs_ar', 'row:opt_jobs'),
            $this->edge('e_men_j', 'main_en', 'jobs_en', 'row:opt_jobs'),
            $this->edge('e_j_ar_rm', 'jobs_ar', 'route_main', 'out'),
            $this->edge('e_j_en_rm', 'jobs_en', 'route_main', 'out'),

            $this->edge('e_mar_p', 'main_ar', 'partner_ar', 'row:opt_partner'),
            $this->edge('e_men_p', 'main_en', 'partner_en', 'row:opt_partner'),
            $this->edge('e_par_ar', 'partner_ar', 'route_main', 'out'),
            $this->edge('e_par_en', 'partner_en', 'route_main', 'out'),

            $this->edge('e_mar_i', 'main_ar', 'issue_ar', 'row:opt_issue'),
            $this->edge('e_men_i', 'main_en', 'issue_en', 'row:opt_issue'),
            $this->edge('e_iss_ar', 'issue_ar', 'route_main', 'out'),
            $this->edge('e_iss_en', 'issue_en', 'route_main', 'out'),

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
