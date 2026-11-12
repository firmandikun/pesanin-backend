<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatLabel;
use App\Models\ChatNote;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\QuickReply;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Inbox collaboration + CRM on top of the multi-channel chat:
 * per-conversation assignee / status / labels / internal notes, quick replies, and customers
 * (linked to chats and matched to orders by phone).
 */
class InboxController extends Controller
{
    private const KEY_RULES = [
        'channel' => 'required|in:shopee,facebook,instagram,whatsapp',
        'source' => 'required|string|max:64',
        'conversation' => 'required|string|max:191',
    ];

    /** Adds thread metadata to inbox rows; a "done" chat reopens when the buyer writes again. */
    public static function decorate(User $user, array $convs): array
    {
        $threads = ChatThread::where('user_id', $user->id)->get()
            ->keyBy(fn ($t) => "{$t->channel}|{$t->source_id}|{$t->conversation_id}");
        $team = TeamMember::where('user_id', $user->id)->pluck('name', 'id');

        return array_map(function ($c) use ($threads, $team) {
            $t = $threads->get("{$c['channel']}|{$c['source_id']}|{$c['id']}");
            $status = $t?->status ?? 'open';
            if ($status === 'done' && empty($c['last_from_me']) && ! empty($c['at']) && $t->done_at && Carbon::parse($c['at'])->gt($t->done_at)) {
                $status = 'open';
            }

            return $c + [
                'status' => $status,
                'assigned_to' => $t?->assigned_to,
                'assignee' => $t?->assigned_to ? $team->get($t->assigned_to) : null,
                'labels' => $t?->labels ?? [],
                'customer_id' => $t?->customer_id,
            ];
        }, $convs);
    }

    /** Team members and labels the inbox needs for its filters and pickers. */
    public static function meta(User $user): array
    {
        return [
            'team' => TeamMember::where('user_id', $user->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'role']),
            'labels' => ChatLabel::where('user_id', $user->id)->orderBy('name')->get(['id', 'name', 'color']),
        ];
    }

    private function key(Request $request): array
    {
        return $request->validate(self::KEY_RULES + ['name' => 'nullable|string|max:191']);
    }

    private function thread(Request $request, bool $create = true): ?ChatThread
    {
        $k = $this->key($request);
        $q = ChatThread::where('user_id', $request->user()->id)->where('channel', $k['channel'])
            ->where('source_id', $k['source'])->where('conversation_id', $k['conversation']);
        if (! $create) {
            return $q->first();
        }
        $t = $q->first() ?? new ChatThread(['channel' => $k['channel'], 'source_id' => $k['source'], 'conversation_id' => $k['conversation']]);
        $t->user_id = $request->user()->id;
        if (! empty($k['name'])) {
            $t->name = $k['name'];
        }

        return $t;
    }

    /** Side panel for one conversation: status, notes, linked customer (or a phone match to suggest). */
    public function show(Request $request)
    {
        $t = $this->thread($request, false);
        $phone = Customer::normalizePhone($request->query('phone'));
        $suggest = ! $t?->customer_id && $phone
            ? Customer::where('user_id', $request->user()->id)->get()->first(fn ($c) => Customer::normalizePhone($c->phone) === $phone)
            : null;

        return [
            'thread' => [
                'status' => $t?->status ?? 'open', 'assigned_to' => $t?->assigned_to, 'labels' => $t?->labels ?? [],
            ],
            'notes' => $t ? $t->notes()->get(['id', 'author', 'body', 'created_at']) : [],
            'customer' => $t?->customer ? $this->customerCard($t->customer) : null,
            'suggest' => $suggest ? $this->customerCard($suggest) : null,
        ];
    }

    public function update(Request $request)
    {
        $uid = $request->user()->id;
        $data = $request->validate([
            'assigned_to' => ['sometimes', 'nullable', Rule::exists('team_members', 'id')->where('user_id', $uid)],
            'status' => 'sometimes|in:open,done',
            'labels' => 'sometimes|array|max:10', 'labels.*' => 'string|max:40',
        ]);
        $t = $this->thread($request);
        if (array_key_exists('status', $data)) {
            $t->done_at = $data['status'] === 'done' ? now() : null;
        }
        if (isset($data['labels'])) {
            $data['labels'] = array_values(array_unique($data['labels']));
            foreach ($data['labels'] as $name) {
                ChatLabel::remember($uid, $name);
            }
        }
        $t->fill($data)->save();

        return ['thread' => ['status' => $t->status, 'assigned_to' => $t->assigned_to, 'labels' => $t->labels ?? []]];
    }

    public function addNote(Request $request)
    {
        $body = $request->validate(['body' => 'required|string|max:2000'])['body'];
        $t = $this->thread($request);
        $t->save();

        return ['note' => $t->notes()->create(['author' => \App\Support\Member::actorName($request), 'body' => $body])->only(['id', 'author', 'body', 'created_at'])];
    }

    public function deleteNote(Request $request, ChatNote $note)
    {
        abort_unless(ChatThread::whereKey($note->chat_thread_id)->where('user_id', $request->user()->id)->exists(), 404);
        $note->delete();

        return response()->noContent();
    }

    /** Link the chat to an existing customer, create one from the chat, or unlink (customer_id null). */
    public function link(Request $request)
    {
        $uid = $request->user()->id;
        $data = $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('user_id', $uid)],
            'customer' => 'nullable|array',
            'customer.name' => 'required_with:customer|string|max:120',
            'customer.phone' => 'nullable|string|max:30',
            'customer.email' => 'nullable|email|max:120',
        ]);
        $t = $this->thread($request);
        if (! empty($data['customer'])) {
            $c = new Customer($this->cleanCustomer($data['customer']) + ['source' => $t->channel]);
            $c->user_id = $uid;
            $c->save();
            $t->customer_id = $c->id;
        } else {
            $t->customer_id = $data['customer_id'] ?? null;
        }
        $t->save();

        return ['customer' => $t->customer ? $this->customerCard($t->customer) : null];
    }

    // ── Labels ────────────────────────────────────────────────

    public function labels(Request $request)
    {
        return ['data' => ChatLabel::where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name', 'color'])];
    }

    public function storeLabel(Request $request)
    {
        $uid = $request->user()->id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('chat_labels')->where('user_id', $uid)],
            'color' => 'nullable|in:slate,red,orange,amber,green,teal,blue,violet,pink',
        ]);
        $l = new ChatLabel($data + ['color' => 'slate']);
        $l->user_id = $uid;
        $l->save();

        return ['label' => $l->only(['id', 'name', 'color'])];
    }

    public function updateLabel(Request $request, ChatLabel $label)
    {
        abort_unless($label->user_id === $request->user()->id, 404);
        $label->update($request->validate(['color' => 'required|in:slate,red,orange,amber,green,teal,blue,violet,pink']));

        return ['label' => $label->only(['id', 'name', 'color'])];
    }

    /** Deleting a label also removes it from every chat and customer. */
    public function deleteLabel(Request $request, ChatLabel $label)
    {
        $uid = $request->user()->id;
        abort_unless($label->user_id === $uid, 404);
        foreach ([ChatThread::class, Customer::class] as $model) {
            $model::where('user_id', $uid)->whereNotNull('labels')->get()->each(function ($row) use ($label) {
                if (in_array($label->name, $row->labels ?? [], true)) {
                    $row->update(['labels' => array_values(array_diff($row->labels, [$label->name]))]);
                }
            });
        }
        $label->delete();

        return response()->noContent();
    }

    // ── Quick replies ─────────────────────────────────────────

    public function quickReplies(Request $request)
    {
        return ['data' => QuickReply::where('user_id', $request->user()->id)->orderBy('category')->orderBy('shortcut')->get(['id', 'shortcut', 'category', 'body'])];
    }

    private function replyRules(Request $request, ?int $ignore = null): array
    {
        return [
            'shortcut' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/i', Rule::unique('quick_replies')->where('user_id', $request->user()->id)->ignore($ignore)],
            'category' => 'nullable|string|max:60',
            'body' => 'required|string|max:2000',
        ];
    }

    public function storeQuickReply(Request $request)
    {
        $r = new QuickReply($request->validate($this->replyRules($request)));
        $r->user_id = $request->user()->id;
        $r->save();

        return ['reply' => $r->only(['id', 'shortcut', 'category', 'body'])];
    }

    public function updateQuickReply(Request $request, QuickReply $reply)
    {
        abort_unless($reply->user_id === $request->user()->id, 404);
        $reply->update($request->validate($this->replyRules($request, $reply->id)));

        return ['reply' => $reply->only(['id', 'shortcut', 'category', 'body'])];
    }

    public function deleteQuickReply(Request $request, QuickReply $reply)
    {
        abort_unless($reply->user_id === $request->user()->id, 404);
        $reply->delete();

        return response()->noContent();
    }

    // ── Customers ─────────────────────────────────────────────

    public function customers(Request $request)
    {
        $q = Customer::where('user_id', $request->user()->id)
            ->when($request->query('q'), function ($w, $s) {
                $digits = preg_replace('/\D+/', '', $s);
                $w->where(function ($x) use ($s, $digits) {
                    $x->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%");
                    if (strlen($digits) >= 3) {
                        $x->orWhere('phone', 'like', "%$digits%")->orWhere('phone', 'like', '%'.Customer::normalizePhone($digits).'%');
                    }
                });
            })
            ->when($request->query('label'), fn ($w, $l) => $w->whereJsonContains('labels', $l))
            ->latest();
        $page = $q->paginate(min(1000, max(5, (int) $request->query('per_page', 10))));
        $page->setCollection($page->getCollection()->map(fn ($c) => $this->customerCard($c, false)));

        return $page;
    }

    private function customerRules(): array
    {
        return [
            'name' => 'required|string|max:120', 'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s-]{6,20}$/'],
            'email' => 'nullable|email|max:120', 'labels' => 'nullable|array|max:10', 'labels.*' => 'string|max:40',
            'notes' => 'nullable|string|max:5000', 'opt_in' => 'boolean',
        ];
    }

    public function storeCustomer(Request $request)
    {
        $c = new Customer($this->cleanCustomer($request->validate($this->customerRules())) + ['source' => 'manual']);
        $c->user_id = $request->user()->id;
        $c->save();
        $this->rememberLabels($c);

        return ['customer' => $this->customerCard($c)];
    }

    public function showCustomer(Request $request, Customer $customer)
    {
        abort_unless($customer->user_id === $request->user()->id, 404);

        return ['customer' => $this->customerCard($customer) + [
            'chats' => $customer->threads()->get(['channel', 'source_id', 'conversation_id', 'name', 'status'])
                ->map(fn ($t) => ['channel' => $t->channel, 'source_id' => $t->source_id, 'id' => $t->conversation_id, 'name' => $t->name, 'status' => $t->status]),
        ]];
    }

    public function updateCustomer(Request $request, Customer $customer)
    {
        abort_unless($customer->user_id === $request->user()->id, 404);
        $customer->update($this->cleanCustomer($request->validate($this->customerRules())));
        $this->rememberLabels($customer);

        return ['customer' => $this->customerCard($customer)];
    }

    public function deleteCustomer(Request $request, Customer $customer)
    {
        abort_unless($customer->user_id === $request->user()->id, 404);
        $customer->delete();

        return response()->noContent();
    }

    /** Phones are stored as 62… digits so chats and orders match regardless of how they were typed. */
    private function cleanCustomer(array $data): array
    {
        if (array_key_exists('phone', $data)) {
            $data['phone'] = Customer::normalizePhone($data['phone']);
        }

        return $data;
    }

    private function rememberLabels(Customer $c): void
    {
        foreach ($c->labels ?? [] as $name) {
            ChatLabel::remember($c->user_id, $name);
        }
    }

    /** Customer with order stats (paid/active orders only for "spent"); recent orders in the full card. */
    private function customerCard(Customer $c, bool $full = true): array
    {
        $orders = $c->orders();
        $valid = (clone $orders)->whereNotIn('status', ['cancelled', 'refunded']);
        $card = $c->only(['id', 'name', 'phone', 'email', 'labels', 'notes', 'opt_in', 'source', 'created_at']) + [
            'labels' => $c->labels ?? [],
            'orders_count' => (clone $orders)->count(),
            'spent' => (int) (clone $valid)->sum('total'),
            'last_order_at' => (clone $orders)->max('created_at'),
        ];
        if ($full) {
            $card['orders'] = (clone $orders)->latest()->take(5)->get(['id', 'code', 'total', 'status', 'payment_status', 'shipping_status', 'created_at']);
        }

        return $card;
    }
}
