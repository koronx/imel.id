import { createTheme } from '@mui/material/styles';

const FONT = '"Google Sans", Roboto, "Helvetica Neue", Arial, sans-serif';

const densityMetrics = {
  compact: { row: 32, listPadding: 0 },
  comfortable: { row: 40, listPadding: 2 },
  default: { row: 48, listPadding: 4 },
};

export function rowHeight(density) {
  return (densityMetrics[density] || densityMetrics.default).row;
}

/** Google-flavoured palette: light surfaces, blue accents, red brand mark. */
export function buildTheme(mode = 'light', density = 'default') {
  const dark = mode === 'dark';

  return createTheme({
    palette: {
      mode: dark ? 'dark' : 'light',
      primary: { main: dark ? '#8ab4f8' : '#1a73e8', contrastText: dark ? '#202124' : '#ffffff' },
      secondary: { main: '#ea4335' },
      warning: { main: '#f4b400' },
      success: { main: '#188038' },
      background: {
        default: dark ? '#202124' : '#f6f8fc',
        paper: dark ? '#292a2d' : '#ffffff',
      },
      text: {
        primary: dark ? '#e8eaed' : '#202124',
        secondary: dark ? '#9aa0a6' : '#5f6368',
      },
      divider: dark ? '#3c4043' : '#e0e0e0',
      action: {
        hover: dark ? 'rgba(232,234,237,0.08)' : 'rgba(32,33,36,0.039)',
        selected: dark ? 'rgba(138,180,248,0.24)' : '#c2e7ff',
      },
    },
    shape: { borderRadius: 8 },
    typography: {
      fontFamily: FONT,
      fontSize: 14,
      button: { textTransform: 'none', fontWeight: 500, letterSpacing: 0.15 },
      body2: { fontSize: '0.875rem' },
      caption: { fontSize: '0.75rem' },
    },
    density,
    components: {
      MuiCssBaseline: {
        styleOverrides: {
          'html, body, #root': { height: '100%' },
          body: { overflow: 'hidden' },
          '::-webkit-scrollbar': { width: 12, height: 12 },
          '::-webkit-scrollbar-thumb': {
            backgroundColor: dark ? '#5f6368' : '#dadce0',
            borderRadius: 8,
            border: `3px solid ${dark ? '#202124' : '#f6f8fc'}`,
          },
          '::-webkit-scrollbar-thumb:hover': { backgroundColor: dark ? '#80868b' : '#bdc1c6' },
        },
      },
      MuiButton: {
        styleOverrides: {
          root: { borderRadius: 18, paddingInline: 20 },
          contained: { boxShadow: 'none', '&:hover': { boxShadow: '0 1px 3px rgba(60,64,67,.3)' } },
        },
      },
      MuiIconButton: { styleOverrides: { root: { color: dark ? '#9aa0a6' : '#5f6368' } } },
      MuiTooltip: {
        defaultProps: { enterDelay: 500 },
        styleOverrides: { tooltip: { fontSize: 11, backgroundColor: '#3c4043' } },
      },
      MuiListItemButton: {
        styleOverrides: {
          root: {
            borderRadius: '0 16px 16px 0',
            paddingTop: 2,
            paddingBottom: 2,
            minHeight: 32,
          },
        },
      },
      MuiChip: { styleOverrides: { root: { borderRadius: 6, height: 20, fontSize: 11 } } },
      MuiPaper: { defaultProps: { elevation: 0 } },
      MuiDialog: { styleOverrides: { paper: { borderRadius: 8 } } },
    },
  });
}
