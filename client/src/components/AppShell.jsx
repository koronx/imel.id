import { useEffect, useState } from 'react';
import {
  Box, Button, Drawer, Snackbar, useMediaQuery, useTheme,
} from '@mui/material';
import TopBar from './TopBar';
import Sidebar from './Sidebar';
import ComposeWindow from './ComposeWindow';
import KeyboardShortcuts from './KeyboardShortcuts';
import { useMail } from '../state/MailContext';

export default function AppShell({ children }) {
  const theme = useTheme();
  const isMobile = useMediaQuery(theme.breakpoints.down('md'));
  const { composers, toast, setToast } = useMail();
  const [open, setOpen] = useState(true);

  useEffect(() => {
    setOpen(!isMobile);
  }, [isMobile]);

  const sidebar = <Sidebar collapsed={!open && !isMobile} />;

  return (
    <Box sx={{ height: '100vh', display: 'flex', flexDirection: 'column', bgcolor: 'background.default' }}>
      <TopBar onToggleSidebar={() => setOpen((value) => !value)} />

      <Box sx={{ flex: 1, display: 'flex', minHeight: 0 }}>
        {isMobile ? (
          <Drawer open={open} onClose={() => setOpen(false)} PaperProps={{ sx: { width: 280, pt: 1 } }}>
            {sidebar}
          </Drawer>
        ) : sidebar}

        <Box
          component="main"
          sx={{
            flex: 1, minWidth: 0, mr: { xs: 0, md: 1 }, mb: { xs: 0, md: 1 },
            borderRadius: { xs: 0, md: 4 }, bgcolor: 'background.paper',
            display: 'flex', flexDirection: 'column', overflow: 'hidden',
          }}
        >
          {children}
        </Box>
      </Box>

      {/* Compose windows dock to the bottom-right, exactly like Gmail */}
      <Box
        sx={{
          position: 'fixed', right: 0, bottom: 0, display: 'flex', alignItems: 'flex-end',
          gap: 1.5, pr: 2, zIndex: (t) => t.zIndex.modal, pointerEvents: 'none',
        }}
      >
        {composers.map((composer) => (
          <Box key={composer.id} sx={{ pointerEvents: 'auto' }}>
            <ComposeWindow composer={composer} />
          </Box>
        ))}
      </Box>

      <Snackbar
        key={toast?.key}
        open={Boolean(toast)}
        onClose={() => setToast(null)}
        autoHideDuration={toast?.action ? 8000 : 4000}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'left' }}
        message={toast?.message}
        sx={{ mb: { xs: 1, md: 2 }, ml: { xs: 1, md: 2 } }}
        action={toast?.action ? (
          <Button
            size="small"
            sx={{ color: '#8ab4f8' }}
            onClick={() => { toast.action.onClick(); setToast(null); }}
          >
            {toast.action.label}
          </Button>
        ) : undefined}
      />

      <KeyboardShortcuts />
    </Box>
  );
}
