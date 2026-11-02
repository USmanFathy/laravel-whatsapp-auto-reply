import { useCallback, useEffect, useState } from 'react';
import { BellOff, Bell } from 'lucide-react';
import { webhookApi, type Webhook, type WebhookFilterCondition } from '../../services/api';
import { useToast } from '../../hooks/useToast';

// Only the webhook that points at the Laravel auto-reply app is touched.
const AUTO_REPLY_URL_PART = '/api/openwa/webhook';

const asList = (v: WebhookFilterCondition['value'] | undefined): string[] =>
  Array.isArray(v) ? v : typeof v === 'string' ? [v] : [];

const blockedIn = (w: Webhook): string[] => {
  const c = w.filters?.conditions.find(x => x.field === 'chatId' && x.operator === 'isNot');
  return asList(c?.value);
};

interface Props {
  sessionId: string | null;
  chatId: string;
}

export default function AutoReplyToggle({ sessionId, chatId }: Props) {
  const { success, error } = useToast();
  const [webhooks, setWebhooks] = useState<Webhook[]>([]);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    if (!sessionId) return setWebhooks([]);
    try {
      const all = await webhookApi.listBySession(sessionId);
      setWebhooks(all.filter(w => w.url.includes(AUTO_REPLY_URL_PART)));
    } catch {
      setWebhooks([]);
    }
  }, [sessionId]);

  useEffect(() => {
    void load();
  }, [load, chatId]);

  if (!sessionId || webhooks.length === 0) return null; // no auto-reply webhook registered: nothing to toggle

  const stopped = webhooks.every(w => blockedIn(w).includes(chatId));

  const toggle = async () => {
    setBusy(true);
    try {
      for (const w of webhooks) {
        const others = (w.filters?.conditions ?? []).filter(c => !(c.field === 'chatId' && c.operator === 'isNot'));
        const current = blockedIn(w);
        const next = stopped ? current.filter(id => id !== chatId) : [...new Set([...current, chatId])];
        const conditions: WebhookFilterCondition[] = next.length
          ? [...others, { field: 'chatId', operator: 'isNot', value: next }]
          : others;
        await webhookApi.update(sessionId, w.id, { filters: { conditions } });
      }
      success(stopped ? 'Auto-reply resumed for this chat' : 'Auto-reply stopped for this chat');
      await load();
    } catch (err) {
      error('Could not update the webhook filter', err instanceof Error ? err.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  return (
    <button
      type="button"
      className={`icon-btn ${stopped ? 'active' : ''}`}
      onClick={() => void toggle()}
      disabled={busy}
      title={stopped ? 'Auto-reply is STOPPED for this chat. Click to resume' : 'Auto-reply is ON for this chat. Click to stop'}
      aria-label={stopped ? 'Resume auto-reply for this chat' : 'Stop auto-reply for this chat'}
      aria-pressed={stopped}
    >
      {stopped ? <BellOff size={22} /> : <Bell size={22} />}
    </button>
  );
}