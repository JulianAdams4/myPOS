/* eslint-disable no-undef */
/* eslint-disable import/no-extraneous-dependencies */
import React, { Component, Suspense } from 'react';
import { Redirect, Route, Switch } from 'react-router-dom';
import { connect } from 'react-redux';
import { Container } from 'reactstrap';
import {
  AppFooter,
  AppHeader,
  AppSidebar,
  AppSidebarFooter,
  AppSidebarForm,
  AppSidebarHeader,
  AppSidebarMinimizer,
  AppSidebarNav,
} from '@coreui/react';
import Pusher from 'pusher-js';
import actions from '../../js/redux/user/actions';
import routes from '../../js/routes';

const { logout } = actions;
const DefaultFooter = React.lazy(() => import('./DefaultFooter'));
const DefaultHeader = React.lazy(() => import('./DefaultHeader'));

class DefaultLayout extends Component {
  constructor(props) {
    super(props);
    const jsonOut = Object.create(null);
    jsonOut.items = [
      {
        name: 'Cargando...',
        url: '/',
        icon: '',
      }
    ];
    this.state = {
      pusher: new Pusher('b16157b01455f1fa54c0', {
        cluster: 'us2',
        forceTLS: true
      }),
      user: {},
      items: jsonOut,
      redirectMain: '/',
    };
  }

  componentDidMount() {
    const { user } = this.props;
    if (user !== undefined) {
      const { permissions } = user;
      let newNavOptions = [];
      let newRedirectMain = '/';
      if (user.role === 'admin_store') {
        newRedirectMain = '/dashboard';
        const modules = [
          {
            identifier: 'inventory',
            data: {
              name: 'Inventario',
              url: '/items',
              icon: 'icon-grid'
            }
          },
          {
            identifier: 'stock-transfers',
            data: {
              name: 'Movimientos',
              url: '/transfers',
              icon: 'icon-basket-loaded'
            }
          },
          {
            identifier: 'taxes',
            data: {
              name: 'Impuestos',
              url: '/store_taxes',
              icon: 'icon-calculator'
            }
          },
          {
            identifier: 'menus',
            data: {
              name: 'Menús',
              url: '/menus',
              icon: 'icon-book-open'
            }
          },
          {
            identifier: 'orders',
            data: {
              name: 'Órdenes',
              url: '/dashboard',
              icon: 'icon-notebook'
            }
          },
          {
            identifier: 'manage-employees',
            data: {
              name: 'Empleados',
              url: '/employees',
              icon: 'icon-user'
            }
          },
          {
            identifier: 'reports',
            data: {
              name: 'Reportes',
              url: '/reports',
              icon: 'icon-calculator'
            }
          },
          {
            identifier: 'goals',
            data: {
              name: 'Metas',
              url: '/goals',
              icon: 'icon-calculator'
            }
          },
          {
            identifier: 'providers',
            data: {
              name: 'Proveedores',
              url: '/providers',
              icon: 'icon-notebook',
            }
          },
          {
            identifier: 'configuration',
            data: {
              name: 'Configuración',
              url: '/settings',
              icon: 'icon-settings'
            }
          }
        ];
        newNavOptions = this.getNavOptionsFromPermissions(modules, permissions);
      } else if (user.role === 'employee' && user.type === 3) {
        // this.listenIntegrationTokens(user);
        const modules = [
          {
            identifier: 'cashier-balance',
            data: {
              name: 'Caja',
              url: '/balance',
              icon: 'icon-calculator',
            }
          },
          {
            identifier: 'commands',
            data: {
              name: 'Comanda Digital',
              url: '/digital',
              icon: 'icon-screen-desktop'
            }
          },
          {
            identifier: 'manage-orders',
            data: {
              name: 'Crear Orden',
              url: '/orders',
              icon: 'icon-book-open'
            }
          },
          {
            identifier: 'view-orders',
            data: {
              name: 'Órdenes',
              url: '/employee_orders',
              icon: 'icon-notebook'
            }
          },
          {
            identifier: 'change-employee',
            data: {
              name: 'Relevo',
              url: '/change_employee',
              icon: 'icon-refresh'
            }
          }
        ];
        newNavOptions = this.getNavOptionsFromPermissions(modules, permissions);
        if (user.id !== 7) {
          newRedirectMain = '/balance';
        } else {
          newRedirectMain = '/digital';
        }
      } else if (user.role === 'admin') {
        newRedirectMain = '/companies';
        newNavOptions.push({
          name: 'Companias',
          url: '/companies',
          icon: 'icon-wrench',
        });
      }

      // super admin navigation
      if (user.isSuperAdmin) {
        newNavOptions.push({
          name: 'Compañías',
          url: '/companies',
          icon: 'icon-wrench',
        });
      }

        const jsonOut = Object.create(null);
          jsonOut.items = newNavOptions;
          this.setState(
            { user, items: jsonOut, redirectMain: newRedirectMain }
          );
        }

      
  }

  getNavOptionsFromPermissions = (modules, permissions) => {
    const modulesArray = [];
    if(permissions!=undefined){
      for (let i = 0; i < modules.length; i += 1) {
        if (permissions.includes(modules[i].identifier)) {
          modulesArray.push(modules[i].data);
        }
      }
    }else{
      this.signOut();
    }
    
    return modulesArray;
  }

  loading = () => <div className="animated fadeIn pt-1 text-center">Cargando...</div>

  signOut(e) {
    const { pusher } = this.state;
    pusher.disconnect();
    const user = this.props.user;
    laravelEcho.channel(`orderUpdateComanda${user.store_id}`)
      .stopListening('OrderUpdatedComanda');
    laravelEcho.channel(`orderDeleted${user.store_id}`)
      .stopListening('OrderDeleted');
    laravelEcho.channel(`newIncomingOrder${user.store_id}`)
      .stopListening('OrderDeleted');
    laravelEcho.channel(`newOrderIntegration${user.store_id}`)
      .stopListening('OrderDeleted');
    laravelEcho.channel(`newOrderIntegrationFailed${user.store_id}`)
      .stopListening('OrderDeleted');
    laravelEcho.channel(`spotCreated${user.store_id}`)
      .stopListening('SpotCreated');
    if(e!=undefined) e.preventDefault();
    // logout
    this.props.logout();
  }

  // eslint-disable-next-line class-methods-use-this
  // listenIntegrationTokens(user) {
  //   // eslint-disable-next-line prefer-template
  //   // eslint-disable-next-line no-undef
  //   laravelEcho.channel(`integrationOrderCreated${user.store_id}`)
  //     .listen('IntegrationOrderCreated', (e) => {
  //       console.log('IntegrationOrderCreated');
  //       console.log(e);
  //     });
  // }

  render() {
    const { items, user, pusher, redirectMain } = this.state;
    return (
      (this.props.user && this.props.user.token)
        ? (
          <div className="app">
            <AppHeader fixed style={{ zIndex: 1 }}>
              <Suspense fallback={this.loading()}>
                <DefaultHeader onLogout={e => this.signOut(e)} user={user} />
              </Suspense>
            </AppHeader>
            <div className="app-body">
              <AppSidebar fixed display="lg" style={{ zIndex: 1 }}>
                <AppSidebarHeader />
                <AppSidebarForm />
                <Suspense>
                  <AppSidebarNav navConfig={items} {...this.props} />
                </Suspense>
                <AppSidebarFooter />
                <AppSidebarMinimizer />
              </AppSidebar>
              <main className="main">
                <Container fluid style={{ marginTop: 16 }}>
                  <Suspense fallback={this.loading()}>
                    <Switch>
                      {routes.map((route, idx) => (route.component ? (
                        <Route
                          key={idx}
                          path={route.path}
                          exact={route.exact}
                          name={route.name}
                          render={props => (
                            <route.component {...props} pusher={pusher} user={user} />
                          )}
                        />
                      ) : (null)))}
                      <Redirect from="/" to={redirectMain} />
                    </Switch>
                  </Suspense>
                </Container>
              </main>
            </div>
            <AppFooter>
              <Suspense fallback={this.loading()}>
                <DefaultFooter />
              </Suspense>
            </AppFooter>
          </div>
        )
        : (
          <Redirect to={{ pathname: '/signin' }} />
        )
    );
  }
}



const mapStateToProps = state => ({
  user: state.user.profile,
});
function mapDispatchToProps(dispatch) {
  return {
    logout: () => dispatch(logout())
  };
}


export default React.memo(connect(
  mapStateToProps,
  mapDispatchToProps
)(DefaultLayout));
