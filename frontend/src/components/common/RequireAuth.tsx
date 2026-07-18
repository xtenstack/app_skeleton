import { PropsWithChildren } from 'react';
import { Navigate } from 'react-router';
import { useAuth } from 'providers/AuthProvider';
import paths from 'routes/paths';
import PageLoader from 'components/loading/PageLoader';

const RequireAuth = ({ children }: PropsWithChildren) => {
  const { user, loading } = useAuth();

  if (loading) {
    return <PageLoader />;
  }

  if (!user) {
    return <Navigate to={paths.login} replace />;
  }

  return children;
};

export default RequireAuth;
