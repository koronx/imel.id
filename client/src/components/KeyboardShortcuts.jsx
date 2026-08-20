import { useEffect, useState } from 'react';
import {
  Dialog, DialogContent, DialogTitle, Divider, Stack, Typography, Box,
} from '@mui/material';
import { useMail } from '../state/MailContext';

const SHORTCUTS = [
  ['c', 'Tulis pesan baru'],
  ['/', 'Fokus ke kotak penelusuran'],
  ['g lalu i', 'Buka Inbox'],
  ['g lalu s', 'Buka Berbintang'],
  ['g lalu t', 'Buka Terkirim'],
  ['g lalu d', 'Buka Draf'],
  ['e', 'Arsipkan percakapan terpilih'],
  ['#', 'Pindahkan ke sampah'],
  ['s', 'Beri/hapus bintang'],
  ['u', 'Kembali ke daftar'],
  ['r', 'Muat ulang daftar'],
  ['?', 'Tampilkan pintasan ini'],
];

/** Global keyboard handling, including the two-key "g then x" sequences. */
export default function KeyboardShortcuts() {
  const {
    openCompose, openLabel, closeThread, refresh, selected, selectionTarget, actions, threadKey,
  } = useMail();
  const [helpOpen, setHelpOpen] = useState(false);
  const [pendingG, setPendingG] = useState(false);

  useEffect(() => {
    const isTyping = (target) => {
      const tag = target.tagName;
      return tag === 'INPUT' || tag === 'TEXTAREA' || target.isContentEditable;
    };

    const onKeyDown = (event) => {
      if (event.metaKey || event.ctrlKey || event.altKey || isTyping(event.target)) return;

      if (pendingG) {
        setPendingG(false);
        const target = { i: 'inbox', s: 'starred', t: 'sent', d: 'drafts', a: 'archive' }[event.key];
        if (target) {
          event.preventDefault();
          openLabel(target);
          return;
        }
      }

      switch (event.key) {
        case 'g':
          setPendingG(true);
          break;
        case 'c':
          event.preventDefault();
          openCompose();
          break;
        case 'u':
          if (threadKey) closeThread();
          break;
        case 'r':
          refresh();
          break;
        case 'e':
          if (selected.size) actions.archive(selectionTarget);
          break;
        case '#':
          if (selected.size) actions.trash(selectionTarget);
          break;
        case '?':
          setHelpOpen(true);
          break;
        case 'Escape':
          setHelpOpen(false);
          break;
        default:
          break;
      }
    };

    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [pendingG, openCompose, openLabel, closeThread, refresh, selected, selectionTarget, actions, threadKey]);

  return (
    <Dialog open={helpOpen} onClose={() => setHelpOpen(false)} maxWidth="xs" fullWidth>
      <DialogTitle sx={{ fontSize: 18 }}>Pintasan keyboard</DialogTitle>
      <Divider />
      <DialogContent>
        <Stack spacing={1.25}>
          {SHORTCUTS.map(([keys, description]) => (
            <Box key={keys} sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
              <Box
                component="kbd"
                sx={{
                  minWidth: 64, textAlign: 'center', px: 1, py: 0.25, fontSize: 12,
                  border: '1px solid', borderColor: 'divider', borderRadius: 1,
                  bgcolor: 'action.hover', fontFamily: 'monospace',
                }}
              >
                {keys}
              </Box>
              <Typography variant="body2">{description}</Typography>
            </Box>
          ))}
        </Stack>
      </DialogContent>
    </Dialog>
  );
}
