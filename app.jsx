// app.jsx — assemble three artboards on a design canvas, one per badge variant.

const { useState } = React;

function ArtboardNote({ children }) {
  return (
    <div className="artboard-note">
      <div className="artboard-note__icon">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.01"/></svg>
      </div>
      <div>{children}</div>
    </div>
  );
}

function AdminWindow({ children }) {
  return (
    <div className="admin">
      <Sidebar/>
      <div className="admin__main">
        <Topbar/>
        {children}
      </div>
    </div>
  );
}

function VariantAFrame() {
  const [shopFilter, setShopFilter] = useState('all');
  const list = shopFilter === 'all' ? TOURNEES : TOURNEES.filter(t => t.shop === shopFilter);
  return (
    <AdminWindow>
      <PageHead/>
      <ArtboardNote>
        <strong>Option A — Bandeau boutique (rail vertical)</strong>
        Chaque tournée s'ouvre sur une bande couleur tenue par la boutique : nom en display Vank, ville en petite capitale. Identité forte, lisible de loin, maintien de la hiérarchie L'Atelier (Ruby + Abricot) sur le reste de la tuile. Idéal en réseau multi-franchisés.
      </ArtboardNote>
      <SplitStrip/>
      <Toolbar shopFilter={shopFilter} setShopFilter={setShopFilter}/>
      <div className="tournee-list">
        {list.map(t => <TileA key={t.id} t={t}/>)}
      </div>
    </AdminWindow>
  );
}

function VariantBFrame() {
  const [shopFilter, setShopFilter] = useState('all');
  const list = shopFilter === 'all' ? TOURNEES : TOURNEES.filter(t => t.shop === shopFilter);
  return (
    <AdminWindow>
      <PageHead/>
      <ArtboardNote>
        <strong>Option B — Pastille boutique en ligne</strong>
        Le badge boutique vit dans la zone d'identification de la tournée, sous le nom : avatar coloré + nom + ville. Discret, dense, parfait pour des listes longues. La couleur reste portée par la pastille seule, le reste de la tuile garde le ton brand neutre.
      </ArtboardNote>
      <SplitStrip/>
      <Toolbar shopFilter={shopFilter} setShopFilter={setShopFilter}/>
      <div className="tournee-list">
        {list.map(t => <TileB key={t.id} t={t}/>)}
      </div>
    </AdminWindow>
  );
}

function VariantCFrame() {
  const [shopFilter, setShopFilter] = useState('all');
  const list = shopFilter === 'all' ? TOURNEES : TOURNEES.filter(t => t.shop === shopFilter);
  return (
    <AdminWindow>
      <PageHead/>
      <ArtboardNote>
        <strong>Option C — Tampon livraison</strong>
        Chaque tuile reçoit un liseré fin sur sa tranche haute + un « tampon » d'expédition en haut à droite (épingle + boutique + ville). Métaphore évidente — chaque tournée a un point de départ. Marquage fort sans envahir la grille horizontale.
      </ArtboardNote>
      <SplitStrip/>
      <Toolbar shopFilter={shopFilter} setShopFilter={setShopFilter}/>
      <div className="tournee-list">
        {list.map(t => <TileC key={t.id} t={t}/>)}
      </div>
    </AdminWindow>
  );
}

function App() {
  return (
    <DesignCanvas
      title="Tournées Admin · badge boutique"
      subtitle="[2.7] Delivery Rounds — Shop Badge on Tournée Tile"
    >
      <DCSection id="variants" title="Trois pistes pour rendre la boutique d'attache toujours visible sur la tuile.">
        <DCArtboard id="A" label="A — Bandeau rail" width={1480} height={1020}>
          <VariantAFrame/>
        </DCArtboard>
        <DCArtboard id="B" label="B — Pastille en ligne" width={1480} height={1020}>
          <VariantBFrame/>
        </DCArtboard>
        <DCArtboard id="C" label="C — Tampon livraison" width={1480} height={1020}>
          <VariantCFrame/>
        </DCArtboard>
      </DCSection>
    </DesignCanvas>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App/>);
