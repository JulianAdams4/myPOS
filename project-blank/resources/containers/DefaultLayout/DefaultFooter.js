import React, { Component } from 'react';
import PropTypes from 'prop-types';

const propTypes = {
  children: PropTypes.node
};

const defaultProps = {};

class DefaultFooter extends Component {
  render() {
    // eslint-disable-next-line
		const { children, ...attributes } = this.props;

    return (
      <React.Fragment>
        <span>
          <a href="https://coreui.io">CoreUI</a>
          {' '}
&copy; 2018 creativeLabs.
        </span>
        <span className="ml-auto">
					Powered by
          {' '}
          <a href="https://coreui.io/react">CoreUI for React</a>
        </span>
        {process.env.MIX_SLAVE_SERVER ? (
          <span className="ml-auto">
						E:V 3.4.0 - Modificado por myPOS &copy;
            {' '}
            {new Date().getFullYear()}
          </span>
        ) : (
          <span className="ml-auto">
						V 4.0.0 - Modificado por myPOS &copy;
            {' '}
            {new Date().getFullYear()}
          </span>
        )}
      </React.Fragment>
    );
  }
}

DefaultFooter.propTypes = propTypes;
DefaultFooter.defaultProps = defaultProps;

export default DefaultFooter;
