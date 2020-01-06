export default function render() {
    const ContentComponent = this.state.modal.component || null;

    const content = componentVoter.vote(this.state);

    return (
<AppContext.Provider value={this.state.context}>
    <App.Modal.Modal
        customAttributes={{
            root: {
                className: this.state.modal.component ? `${this.state.modal.component.domain()}:modal/${this.state.modal.component.type()}` : ''
            }
        }}
        title={this.state.modal.title(this.state)}
        open={this.state.modal.open}
        closable={this.state.modal.closable}
        loading={this.state.modal.loading}
    >
        <ModalContext.Provider value={this.state.modal}>
            {ContentComponent !== null &&
            <ContentComponent {...this.state.modal.propsFactory(this.state)} />
            }
        </ModalContext.Provider>
    </App.Modal.Modal>
    <App.FlashBox
        flashes={this.state.flashes}
        onClick={this.handleFlashClick}
    />
    { (this.state.context.appuser
        && (this.state.context.appuser.isGrantedRole(this.state.context.constants.UserRole.roles.EDITOR.value)
            || !!this.state.context.appuser._impersonator
        )
    ) === true &&
    <App.OfficeStrip />
    }
    <div className={`root-core ${this.state.modal.open && this.state.context.probe.mobile ? 'root-core/hidden' : ''}`}>
        <App.Header
            fetching={this.state.fetching}
            menuDisplayed={_.get(componentVoter.table[this.state.context.routing.route], 'menu') !== false}
            menuOpen={this.state.menu.open}
            onToggle={this.handleMenuToggle}
        />
        { this.state.context.routing.office === 'connect' &&
        <div className="connect:logo">
            <Lottie
                options={this.lottie.config}
                segments={this.state.animation.segments}
                eventListeners={this.lottie.eventListeners}
                height={150}
                width={300}
            />
        </div>
        }
        { content }
    </div>
</AppContext.Provider>
      );
};