import { Alert, Box, LinearProgress, Skeleton, Typography } from '@mui/material';
import InboxIcon from '@mui/icons-material/Inbox';
import ThreadRow from './ThreadRow';
import { useMail } from '../state/MailContext';
import { useAuth } from '../state/AuthContext';

const EMPTY_TEXT = {
  inbox: 'Tidak ada email di inbox. Nikmati harimu!',
  starred: 'Belum ada percakapan berbintang.',
  snoozed: 'Fitur tunda belum diaktifkan di server ini.',
  sent: 'Belum ada pesan terkirim.',
  drafts: 'Tidak ada draf tersimpan.',
  spam: 'Tidak ada spam. Bagus!',
  trash: 'Sampah kosong.',
  archive: 'Belum ada email yang diarsipkan.',
  search: 'Tidak ada hasil yang cocok dengan penelusuran Anda.',
};

export default function ThreadList() {
  const {
    threads, loading, error, selected, toggleSelect, actions, openThread, label, query,
    rowKey, targetFor,
  } = useMail();
  const { user, settings } = useAuth();

  if (error) {
    return <Alert severity="error" sx={{ m: 2 }}>{error}</Alert>;
  }

  if (loading && threads.length === 0) {
    return (
      <Box>
        <LinearProgress sx={{ height: 2 }} />
        {Array.from({ length: 12 }).map((_, index) => (
          // eslint-disable-next-line react/no-array-index-key
          <Box key={index} sx={{ display: 'flex', alignItems: 'center', gap: 2, px: 2, height: 48 }}>
            <Skeleton variant="rectangular" width={18} height={18} />
            <Skeleton variant="text" width={150} />
            <Skeleton variant="text" sx={{ flex: 1 }} />
            <Skeleton variant="text" width={48} />
          </Box>
        ))}
      </Box>
    );
  }

  if (threads.length === 0) {
    return (
      <Box sx={{ display: 'grid', placeItems: 'center', flex: 1, p: 6, textAlign: 'center' }}>
        <Box>
          <InboxIcon sx={{ fontSize: 72, color: 'text.disabled' }} />
          <Typography variant="h6" sx={{ mt: 2, fontWeight: 400 }}>
            {query ? EMPTY_TEXT.search : EMPTY_TEXT[label] || 'Tidak ada pesan di sini.'}
          </Typography>
          {query && (
            <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
              Coba operator seperti <code>from:budi</code>, <code>has:attachment</code>, atau <code>is:unread</code>.
            </Typography>
          )}
        </Box>
      </Box>
    );
  }

  return (
    <Box sx={{ flex: 1, overflowY: 'auto' }}>
      {loading && <LinearProgress sx={{ height: 2 }} />}
      {threads.map((thread) => (
        <ThreadRow
          key={rowKey(thread)}
          rowKey={rowKey(thread)}
          target={targetFor([thread])}
          thread={thread}
          label={label}
          me={user?.email}
          density={settings.density}
          selected={selected.has(rowKey(thread))}
          onToggle={toggleSelect}
          onOpen={(row) => openThread(row.thread_key)}
          onStar={(row, starred) => actions.star(targetFor([row]), starred)}
          onImportant={(row, important) => actions.important(targetFor([row]), important)}
          actions={actions}
        />
      ))}
    </Box>
  );
}
