import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { api, onUnauthorized, setToken } from '../api/client';

const AuthContext = createContext(null);

const DEFAULT_SETTINGS = {
  theme: 'light',
  density: 'default',
  reading_pane: 'none',
  per_page: 50,
  conversation_view: true,
  undo_send_seconds: 5,
  signature: '',
  signature_enabled: false,
  language: 'id',
};

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [settings, setSettings] = useState(DEFAULT_SETTINGS);
  const [status, setStatus] = useState('loading'); // loading | authenticated | anonymous

  const signOutLocal = useCallback(() => {
    setToken('');
    setUser(null);
    setStatus('anonymous');
  }, []);

  // the API tells us when a token stopped being valid
  useEffect(() => onUnauthorized(signOutLocal), [signOutLocal]);

  const bootstrap = useCallback(async () => {
    try {
      const data = await api.me();
      setUser(data.user);
      setSettings({ ...DEFAULT_SETTINGS, ...data.settings });
      setStatus('authenticated');
    } catch {
      signOutLocal();
    }
  }, [signOutLocal]);

  useEffect(() => {
    const token = localStorage.getItem('imel.token');
    if (!token) {
      setStatus('anonymous');
      return;
    }
    bootstrap();
  }, [bootstrap]);

  const applySession = useCallback(async (payload) => {
    setToken(payload.auth.token);
    setUser(payload.user);
    setStatus('authenticated');
    try {
      const data = await api.settings();
      setSettings({ ...DEFAULT_SETTINGS, ...data.settings });
    } catch {
      setSettings(DEFAULT_SETTINGS);
    }
  }, []);

  const login = useCallback(
    async (email, password) => applySession(await api.login(email, password)),
    [applySession],
  );

  const register = useCallback(
    async (data) => applySession(await api.register(data)),
    [applySession],
  );

  const logout = useCallback(async () => {
    try {
      await api.logout();
    } finally {
      signOutLocal();
    }
  }, [signOutLocal]);

  const saveSettings = useCallback(async (patch) => {
    const optimistic = { ...settings, ...patch };
    setSettings(optimistic);
    const data = await api.updateSettings(optimistic);
    setSettings({ ...DEFAULT_SETTINGS, ...data.settings });
    return data.settings;
  }, [settings]);

  const saveProfile = useCallback(async (patch) => {
    const data = await api.updateProfile(patch);
    setUser(data.user);
    return data.user;
  }, []);

  const refreshUser = useCallback(async () => {
    const data = await api.me();
    setUser(data.user);
    return data.user;
  }, []);

  const value = useMemo(
    () => ({ user, settings, status, login, register, logout, saveSettings, saveProfile, refreshUser }),
    [user, settings, status, login, register, logout, saveSettings, saveProfile, refreshUser],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) throw new Error('useAuth harus dipakai di dalam AuthProvider');
  return context;
}
