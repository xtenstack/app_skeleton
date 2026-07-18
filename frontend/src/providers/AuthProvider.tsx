import { PropsWithChildren, createContext, use, useEffect, useState } from 'react';
import { ApiUser, api } from 'lib/api';

interface AuthContextInterface {
  user: ApiUser | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
}

export const AuthContext = createContext({} as AuthContextInterface);

const AuthProvider = ({ children }: PropsWithChildren) => {
  const [user, setUser] = useState<ApiUser | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api
      .me()
      .then(({ user }) => setUser(user))
      .catch(() => setUser(null))
      .finally(() => setLoading(false));
  }, []);

  const login = async (email: string, password: string) => {
    const { user } = await api.login(email, password);
    setUser(user);
  };

  const logout = async () => {
    await api.logout();
    setUser(null);
  };

  return <AuthContext value={{ user, loading, login, logout }}>{children}</AuthContext>;
};

export const useAuth = () => use(AuthContext);

export default AuthProvider;
