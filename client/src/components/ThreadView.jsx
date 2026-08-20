import { useCallback, useEffect, useState } from 'react';
import {
  Alert, Box, Chip, CircularProgress, IconButton, Tooltip, Typography,
} from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import ArchiveOutlinedIcon from '@mui/icons-material/ArchiveOutlined';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import ReportGmailerrorredIcon from '@mui/icons-material/ReportGmailerrorred';
import MarkEmailUnreadOutlinedIcon from '@mui/icons-material/MarkEmailUnreadOutlined';
import LabelImportantIcon from '@mui/icons-material/LabelImportant';
import PrintIcon from '@mui/icons-material/Print';
import { api } from '../api/client';
import { useMail } from '../state/MailContext';
import { useAuth } from '../state/AuthContext';
import MessageItem from './MessageItem';

export default function ThreadView() {
  const {
    threadKey, closeThread, actions, replyTo, notify, openCompose, refresh,
  } = useMail();
  const { user } = useAuth();
  const [thread, setThread] = useState(null);
  const [expanded, setExpanded] = useState(() => new Set());
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const data = await api.thread(decodeURIComponent(threadKey), { mark_read: true });
      setThread(data.thread);
      const last = data.thread.messages[data.thread.messages.length - 1];
      setExpanded(new Set([last.id]));
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  }, [threadKey]);

  useEffect(() => {
    load();
  }, [load]);

  const toggle = (id) => setExpanded((current) => {
    const next = new Set(current);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    return next;
  });

  const target = { threadKeys: [decodeURIComponent(threadKey)] };

  const after = (fn) => async () => {
    await fn(target);
    closeThread();
  };

  if (loading) {
    return (
      <Box sx={{ display: 'grid', placeItems: 'center', flex: 1 }}>
        <CircularProgress />
      </Box>
    );
  }

  if (error || !thread) {
    return <Alert severity="error" sx={{ m: 2 }}>{error || 'Percakapan tidak ditemukan'}</Alert>;
  }

  const labels = thread.messages[thread.messages.length - 1].labels || [];

  return (
    <Box sx={{ flex: 1, display: 'flex', flexDirection: 'column', minHeight: 0 }}>
      <Box
        sx={{
          display: 'flex', alignItems: 'center', gap: 0.5, px: 1, py: 0.5, minHeight: 48,
          borderBottom: '1px solid', borderColor: 'divider', flexShrink: 0,
        }}
      >
        <Tooltip title="Kembali ke daftar">
          <IconButton onClick={closeThread}><ArrowBackIcon /></IconButton>
        </Tooltip>
        <Tooltip title="Arsipkan">
          <IconButton onClick={after(actions.archive)}><ArchiveOutlinedIcon /></IconButton>
        </Tooltip>
        <Tooltip title="Laporkan spam">
          <IconButton onClick={after(actions.spam)}><ReportGmailerrorredIcon /></IconButton>
        </Tooltip>
        <Tooltip title="Hapus">
          <IconButton onClick={after(actions.trash)}><DeleteOutlineIcon /></IconButton>
        </Tooltip>
        <Tooltip title="Tandai belum dibaca">
          <IconButton onClick={after((t) => actions.read(t, false))}>
            <MarkEmailUnreadOutlinedIcon />
          </IconButton>
        </Tooltip>
        <Tooltip title="Tandai penting">
          <IconButton onClick={() => actions.important(target, true)}>
            <LabelImportantIcon />
          </IconButton>
        </Tooltip>
        <Tooltip title="Cetak">
          <IconButton onClick={() => window.print()}><PrintIcon /></IconButton>
        </Tooltip>
      </Box>

      <Box sx={{ flex: 1, overflowY: 'auto' }}>
        <Box sx={{ px: { xs: 2, md: 3 }, pt: 3, pb: 1, display: 'flex', alignItems: 'center', gap: 1.5 }}>
          <Typography variant="h6" sx={{ fontWeight: 400, fontSize: 22, flex: 1 }}>
            {thread.subject || '(tanpa subjek)'}
          </Typography>
          {labels.filter((label) => label.type === 'user').map((label) => (
            <Chip
              key={label.id}
              label={label.name}
              size="small"
              onDelete={() => actions.removeLabel(target, label.id)}
              sx={{ bgcolor: label.color, color: '#fff', '& .MuiChip-deleteIcon': { color: '#fff' } }}
            />
          ))}
          <Chip label={`${thread.messages.length} pesan`} size="small" variant="outlined" />
        </Box>

        {thread.messages.map((message) => (
          <MessageItem
            key={message.id}
            message={message}
            me={user?.email}
            expanded={expanded.has(message.id) || thread.messages.length === 1}
            onToggle={() => toggle(message.id)}
            onReply={(mode) => replyTo(message, mode)}
            onStar={(starred) => {
              actions.star({ ids: [message.id] }, starred);
              setThread((current) => ({
                ...current,
                messages: current.messages.map((item) => (
                  item.id === message.id ? { ...item, is_starred: starred } : item)),
              }));
            }}
            onUnread={async () => {
              await actions.read({ ids: [message.id] }, false);
              closeThread();
            }}
            onTrash={async () => {
              await actions.trash({ ids: [message.id] });
              if (thread.messages.length === 1) closeThread();
              else load();
            }}
            onEditDraft={() => {
              openCompose({
                draftId: message.id,
                to: message.to,
                cc: message.cc,
                bcc: message.bcc,
                subject: message.subject,
                html: message.body_html,
                threadKey: message.thread_key,
                attachments: (message.attachments || []).map((item) => ({ ...item, saved: true })),
              });
              notify('Draf dibuka di jendela tulis');
              refresh();
            }}
          />
        ))}
      </Box>
    </Box>
  );
}
