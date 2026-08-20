import {
  createContext, useCallback, useContext, useEffect, useMemo, useRef, useState,
} from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { api } from '../api/client';
import { useAuth } from './AuthContext';

const MailContext = createContext(null);

let composeSeq = 0;

export function MailProvider({ children }) {
  const { user, settings } = useAuth();
  const navigate = useNavigate();
  const { label = 'inbox', threadKey } = useParams();
  const [searchParams, setSearchParams] = useSearchParams();
  const query = searchParams.get('q') || '';
  const page = Number(searchParams.get('page') || 1);

  const [labels, setLabels] = useState([]);
  const [threads, setThreads] = useState([]);
  const [paging, setPaging] = useState({ page: 1, per_page: 50, total: 0, pages: 1, from: 0, to: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [selected, setSelected] = useState(() => new Set());
  const [composers, setComposers] = useState([]);
  const [toast, setToast] = useState(null);

  const requestRef = useRef(null);
  const undoRef = useRef(null);

  // ------------------------------------------------------------- loading
  const loadLabels = useCallback(async () => {
    try {
      const data = await api.labels();
      setLabels(data.labels || []);
    } catch {
      /* sidebar counters are not critical enough to interrupt the user */
    }
  }, []);

  const loadThreads = useCallback(async ({ silent = false } = {}) => {
    if (requestRef.current) requestRef.current.abort();
    const controller = new AbortController();
    requestRef.current = controller;

    if (!silent) setLoading(true);
    setError('');
    try {
      const data = await api.threads(
        {
          label,
          q: query,
          page,
          per_page: settings.per_page,
          flat: settings.conversation_view ? undefined : 1,
        },
        controller.signal,
      );
      setThreads(data.threads || []);
      setPaging(data.paging);
    } catch (err) {
      if (err.name !== 'AbortError') setError(err.message);
    } finally {
      if (requestRef.current === controller) requestRef.current = null;
      setLoading(false);
    }
  }, [label, query, page, settings.per_page, settings.conversation_view]);

  useEffect(() => {
    if (!user) return undefined;
    setSelected(new Set());
    loadThreads();
    return () => requestRef.current?.abort();
  }, [user, loadThreads]);

  useEffect(() => {
    if (user) loadLabels();
  }, [user, loadLabels]);

  // background refresh, like Gmail's periodic inbox poll
  useEffect(() => {
    if (!user) return undefined;
    const timer = setInterval(() => {
      if (document.visibilityState === 'visible') {
        loadThreads({ silent: true });
        loadLabels();
      }
    }, 30000);
    return () => clearInterval(timer);
  }, [user, loadThreads, loadLabels]);

  const refresh = useCallback(async () => {
    await Promise.all([loadThreads(), loadLabels()]);
  }, [loadThreads, loadLabels]);

  // ----------------------------------------------------------- navigation
  const openLabel = useCallback((slug) => {
    setSelected(new Set());
    navigate(`/mail/${slug}`);
  }, [navigate]);

  const openThread = useCallback((key) => {
    navigate(`/mail/${label}/${encodeURIComponent(key)}${query ? `?q=${encodeURIComponent(query)}` : ''}`);
  }, [navigate, label, query]);

  const closeThread = useCallback(() => {
    navigate(`/mail/${label}${query ? `?q=${encodeURIComponent(query)}` : ''}`);
  }, [navigate, label, query]);

  const search = useCallback((value) => {
    const next = new URLSearchParams();
    if (value) next.set('q', value);
    navigate(`/mail/${value ? 'search' : 'inbox'}?${next.toString()}`);
  }, [navigate]);

  const goToPage = useCallback((nextPage) => {
    const next = new URLSearchParams(searchParams);
    next.set('page', String(nextPage));
    setSearchParams(next);
  }, [searchParams, setSearchParams]);

  // -------------------------------------------------------------- actions
  const notify = useCallback((message, action) => setToast({ message, action, key: Date.now() }), []);

  const applyLocal = useCallback((ids, threadKeys, mutate, removeRow) => {
    setThreads((current) => current
      .map((thread) => {
        const touched = threadKeys.includes(thread.thread_key)
          || thread.message_ids.some((id) => ids.includes(id));
        return touched ? mutate(thread) : thread;
      })
      .filter((thread) => !(removeRow
        && (threadKeys.includes(thread.thread_key) || thread.message_ids.some((id) => ids.includes(id))))));
  }, []);

  const runAction = useCallback(async (action, target, options = {}) => {
    const ids = target.ids || [];
    const threadKeys = target.threadKeys || [];
    const { optimistic, removes, undoAction, message } = options;

    const snapshot = threads;
    if (optimistic) applyLocal(ids, threadKeys, optimistic, removes);

    try {
      await api.batch({ ids, thread_keys: threadKeys, action, ...(options.extra || {}) });
      loadLabels();
      if (message) {
        notify(message, undoAction ? {
          label: 'Urungkan',
          onClick: async () => {
            await api.batch({ ids, thread_keys: threadKeys, action: undoAction, ...(options.extra || {}) });
            refresh();
          },
        } : undefined);
      }
    } catch (err) {
      setThreads(snapshot);
      notify(err.message);
    }
  }, [threads, applyLocal, loadLabels, notify, refresh]);

  /**
   * With conversation view on, a row is a whole thread; with it off, a row is a
   * single message. Both selection and batch actions follow that distinction.
   */
  const conversation = settings.conversation_view;

  const rowKey = useCallback(
    (thread) => (conversation ? thread.thread_key : `${thread.thread_key}:${thread.last_message.id}`),
    [conversation],
  );

  const targetFor = useCallback((rows) => (conversation
    ? { threadKeys: rows.map((row) => row.thread_key) }
    : { ids: rows.flatMap((row) => row.message_ids) }), [conversation]);

  const selectedThreads = useMemo(
    () => threads.filter((thread) => selected.has(rowKey(thread))),
    [threads, selected, rowKey],
  );

  const selectionTarget = useMemo(() => targetFor(selectedThreads), [targetFor, selectedThreads]);

  const actions = useMemo(() => ({
    star: (target, starred) => runAction(starred ? 'star' : 'unstar', target, {
      optimistic: (row) => ({ ...row, is_starred: starred }),
    }),

    important: (target, important) => runAction(important ? 'important' : 'unimportant', target, {
      optimistic: (row) => ({ ...row, is_important: important }),
    }),

    read: (target, isRead) => runAction(isRead ? 'read' : 'unread', target, {
      optimistic: (row) => ({ ...row, is_read: isRead, unread_count: isRead ? 0 : Math.max(1, row.unread_count) }),
    }),

    archive: (target) => runAction('archive', target, {
      optimistic: (row) => row,
      removes: label !== 'archive',
      undoAction: label === 'inbox' ? 'inbox' : undefined,
      message: 'Percakapan diarsipkan',
    }),

    trash: (target) => runAction('trash', target, {
      optimistic: (row) => row,
      removes: label !== 'trash',
      undoAction: 'restore',
      message: 'Dipindahkan ke sampah',
    }),

    spam: (target) => runAction('spam', target, {
      optimistic: (row) => row,
      removes: label !== 'spam',
      undoAction: 'not_spam',
      message: 'Dilaporkan sebagai spam',
    }),

    notSpam: (target) => runAction('not_spam', target, {
      optimistic: (row) => row,
      removes: label === 'spam',
      message: 'Dikembalikan ke inbox',
    }),

    restore: (target) => runAction('restore', target, {
      optimistic: (row) => row,
      removes: label === 'trash',
      message: 'Dipulihkan ke inbox',
    }),

    deleteForever: (target) => runAction('delete', target, {
      optimistic: (row) => row,
      removes: true,
      message: 'Pesan dihapus permanen',
    }),

    addLabel: (target, labelId) => runAction('add_label', target, {
      extra: { label_id: labelId },
      message: 'Label ditambahkan',
    }),

    removeLabel: (target, labelId) => runAction('remove_label', target, {
      extra: { label_id: labelId },
      message: 'Label dihapus',
    }),
  }), [runAction, label]);

  // -------------------------------------------------------------- compose
  const openCompose = useCallback((initial = {}) => {
    composeSeq += 1;
    const id = composeSeq;
    setComposers((current) => [
      ...current.filter((composer) => composer.id !== initial.replaceId).slice(-2),
      { id, minimized: false, maximized: false, ...initial },
    ]);
    return id;
  }, []);

  const closeCompose = useCallback((id) => {
    setComposers((current) => current.filter((composer) => composer.id !== id));
  }, []);

  const updateCompose = useCallback((id, patch) => {
    setComposers((current) => current.map((composer) => (
      composer.id === id ? { ...composer, ...patch } : composer)));
  }, []);

  const replyTo = useCallback(async (message, mode = 'reply') => {
    try {
      const data = await api.replyContext(message.id, mode);
      openCompose({
        to: data.compose.to,
        cc: data.compose.cc,
        subject: data.compose.subject,
        html: data.compose.body_html,
        threadKey: data.compose.thread_key,
        inReplyTo: data.compose.in_reply_to,
        references: data.compose.references,
        showCc: data.compose.cc.length > 0,
        mode,
      });
    } catch (err) {
      notify(err.message);
    }
  }, [openCompose, notify]);

  /** Sending waits out the undo window before it actually hits the API. */
  const sendMessage = useCallback((payload) => {
    const delay = Math.max(0, settings.undo_send_seconds) * 1000;

    const dispatch = async () => {
      undoRef.current = null;
      try {
        await api.send({
          to: payload.to,
          cc: payload.cc,
          bcc: payload.bcc,
          subject: payload.subject,
          body_html: payload.html,
          attachment_ids: payload.attachmentIds,
          in_reply_to: payload.inReplyTo,
          references: payload.references,
          thread_key: payload.threadKey,
          draft_id: payload.draftId,
        });
        notify('Pesan terkirim');
        refresh();
      } catch (err) {
        notify(`Gagal mengirim: ${err.message}`);
        openCompose({ ...payload, restored: true });
      }
    };

    if (delay === 0) {
      dispatch();
      return;
    }

    const timer = setTimeout(dispatch, delay);
    undoRef.current = { timer, payload };

    notify('Mengirim pesan...', {
      label: 'Urungkan',
      onClick: () => {
        if (!undoRef.current) return;
        clearTimeout(undoRef.current.timer);
        const restored = undoRef.current.payload;
        undoRef.current = null;
        openCompose({ ...restored, restored: true });
      },
    });
  }, [settings.undo_send_seconds, notify, refresh, openCompose]);

  // ------------------------------------------------------------ selection
  const toggleSelect = useCallback((key) => {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  }, []);

  const selectAll = useCallback((checked) => {
    setSelected(checked ? new Set(threads.map(rowKey)) : new Set());
  }, [threads, rowKey]);

  /** Used by the "unread / starred / read" quick filters above the list. */
  const selectMany = useCallback((predicate) => {
    setSelected(new Set(threads.filter(predicate).map(rowKey)));
  }, [threads, rowKey]);

  const value = useMemo(() => ({
    label, query, page, threadKey,
    labels, threads, paging, loading, error,
    selected, toggleSelect, selectAll, selectMany, selectionTarget, selectedThreads, rowKey, targetFor,
    refresh, loadLabels, openLabel, openThread, closeThread, search, goToPage,
    actions, notify, toast, setToast,
    composers, openCompose, closeCompose, updateCompose, replyTo, sendMessage,
  }), [
    label, query, page, threadKey, labels, threads, paging, loading, error,
    selected, toggleSelect, selectAll, selectMany, selectionTarget, selectedThreads, rowKey, targetFor, refresh, loadLabels,
    openLabel, openThread, closeThread, search, goToPage, actions, notify, toast,
    composers, openCompose, closeCompose, updateCompose, replyTo, sendMessage,
  ]);

  return <MailContext.Provider value={value}>{children}</MailContext.Provider>;
}

export function useMail() {
  const context = useContext(MailContext);
  if (!context) throw new Error('useMail harus dipakai di dalam MailProvider');
  return context;
}
