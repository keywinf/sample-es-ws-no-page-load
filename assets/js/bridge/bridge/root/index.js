export default class Root extends React.Component {
    constructor(props) {
        super(props);

        _.each(require('./methods.js'), (value, key) => this[key] = value.bind(this));

        this.render = require('./render.js').default.bind(this);
        require('./constructor.js').default.bind(this)(props);
    }
}

Root.propTypes = require('./prop-types.js').propTypes;
Root.defaultProps = require('./default-props.js').defaultProps;