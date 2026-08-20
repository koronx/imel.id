import { useMemo } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { Box, CircularProgress, CssBaseline, ThemeProvider, useMediaQuery } from '@mui/material';
import { buildTheme } from './theme';
import { useAuth } from './state/AuthContext';
import { MailProvider } from './state/MailContext';
import LoginPage from './pages/LoginPage';
import RegisterPage from './pages/RegisterPage';
import MailPage from './pages/MailPage';
import SettingsPage from './pages/SettingsPage';
import AppShell from './components/AppShell';

function FullScreenLoader() {
  return (
    <Box sx={{ height: '100vh', display: 'grid', placeItems: 'center' }}>
      <CircularProgress />
    </Box>
  );
}

function RequireAuth({ children }) {
  const { status } = useAuth();
  const location = useLocation();

  if (status === 'loading') return <FullScreenLoader />;
  if (status === 'anonymous') return <Navigate to="/login" replace state={{ from: location }} />;
  return children;
}

export default function App() {
  const { settings, status } = useAuth();
  const prefersDark = useMediaQuery('(prefers-color-scheme: dark)');

  const mode = settings.theme === 'system' ? (prefersDark ? 'dark' : 'light') : settings.theme;
  const theme = useMemo(() => buildTheme(mode, settings.density), [mode, settings.density]);

  return (
    <ThemeProvider theme={theme}>
      <CssBaseline />
      <Routes>
        <Route
          path="/login"
          element={status === 'authenticated' ? <Navigate to="/mail/inbox" replace /> : <LoginPage />}
        />
        <Route
          path="/register"
          element={status === 'authenticated' ? <Navigate to="/mail/inbox" replace /> : <RegisterPage />}
        />

        <Route
          path="/mail/:label"
          element={(
            <RequireAuth>
              <MailProvider>
                <AppShell><MailPage /></AppShell>
              </MailProvider>
            </RequireAuth>
          )}
        />
        <Route
          path="/mail/:label/:threadKey"
          element={(
            <RequireAuth>
              <MailProvider>
                <AppShell><MailPage /></AppShell>
              </MailProvider>
            </RequireAuth>
          )}
        />
        <Route
          path="/settings"
          element={(
            <RequireAuth>
              <MailProvider>
                <AppShell><SettingsPage /></AppShell>
              </MailProvider>
            </RequireAuth>
          )}
        />

        <Route path="/" element={<Navigate to="/mail/inbox" replace />} />
        <Route path="*" element={<Navigate to="/mail/inbox" replace />} />
      </Routes>
    </ThemeProvider>
  );
}
