const state = {
    data: [],
    meta: null,
    lastUpdated: null,
};

const SIMPLE_MAJORITY_MARK = 138;
const PARTY_PALETTE = [
    "#79c0ea",
    "#4f722d",
    "#ef3f23",
    "#8f1f16",
    "#1e1110",
    "#dbd7d4",
    "#e6bc5c",
    "#3d67e5",
    "#6f3fd6",
    "#0e8a78",
    "#b556b3",
    "#8a6f4d",
];

const resultsBody = document.getElementById("resultsBody");
const summaryGrid = document.getElementById("summaryGrid");
const conclusionBody = document.getElementById("conclusionBody");
const heroMeta = document.getElementById("heroMeta");
const tableMeta = document.getElementById("tableMeta");
const filterSummary = document.getElementById("filterSummary");
const seatMap = document.getElementById("seatMap");
const seatLegend = document.getElementById("seatLegend");
const heatMap = document.getElementById("heatMap");
const mapLegend = document.getElementById("mapLegend");
const searchInput = document.getElementById("searchInput");
const chamberFilter = document.getElementById("chamberFilter");
const sortBy = document.getElementById("sortBy");
const sortDirection = document.getElementById("sortDirection");
const onlyProjected = document.getElementById("onlyProjected");
const refreshButton = document.getElementById("refreshButton");
const resetButton = document.getElementById("resetButton");

function formatNumber(value) {
    return new Intl.NumberFormat("en-US").format(value || 0);
}

function formatPercent(value) {
    return `${(value * 100).toFixed(2)}%`;
}

function formatTime(timestamp) {
    if (!timestamp) {
        return "Not available";
    }

    return new Intl.DateTimeFormat("en-US", {
        dateStyle: "medium",
        timeStyle: "short",
    }).format(new Date(timestamp));
}

function hashString(text) {
    let hash = 0;

    for (let index = 0; index < text.length; index += 1) {
        hash = (hash << 5) - hash + text.charCodeAt(index);
        hash |= 0;
    }

    return Math.abs(hash);
}

function getFallbackColor(partyName) {
    const hue = hashString(partyName) % 360;
    return `hsl(${hue} 52% 48%)`;
}

function getPartyColorMap() {
    const rankedParties = [...state.data].sort(
        (left, right) => right.projectedFederalSeats - left.projectedFederalSeats,
    );
    const colorMap = new Map();

    rankedParties.forEach((party, index) => {
        colorMap.set(
            party.partyName,
            PARTY_PALETTE[index] || getFallbackColor(party.partyName),
        );
    });

    return colorMap;
}

function renderLegend(container, items, colorMap, metricKey) {
    if (!items.length) {
        container.innerHTML = "";
        return;
    }

    container.innerHTML = items
        .map(
            (party) => `
        <div class="legend-item">
          <span class="legend-swatch" style="background:${colorMap.get(party.partyName) || getFallbackColor(party.partyName)}"></span>
          <span>${party.partyName}: ${formatNumber(party[metricKey])}</span>
        </div>
      `,
        )
        .join("");
}

function buildSeatPositions(totalSeats, rowCount = 11) {
    const radii = [];
    const counts = [];
    const innerRadius = 0.34;
    const outerRadius = 0.94;
    let weightSum = 0;

    for (let row = 0; row < rowCount; row += 1) {
        const t = rowCount === 1 ? 0 : row / (rowCount - 1);
        const radius = innerRadius + (outerRadius - innerRadius) * t;
        radii.push(radius);
        weightSum += radius;
    }

    let assigned = 0;

    radii.forEach((radius, row) => {
        const remainingRows = rowCount - row;
        const remainingSeats = totalSeats - assigned;
        const estimated = Math.max(6, Math.round((radius / weightSum) * totalSeats));
        const count =
            row === rowCount - 1
                ? remainingSeats
                : Math.min(estimated, remainingSeats - (remainingRows - 1) * 6);
        counts.push(count);
        assigned += count;
    });

    const positions = [];

    counts.forEach((count, row) => {
        const radius = radii[row];

        for (let index = 0; index < count; index += 1) {
            const angle = Math.PI - ((index + 0.5) / count) * Math.PI;
            const x = 360 + Math.cos(angle) * radius * 265;
            const y = 350 - Math.sin(angle) * radius * 250;
            positions.push({ x, y });
        }
    });

    return positions;
}

function getSymbolUrl(symbolId) {
    if (!symbolId) {
        return "";
    }

    return `https://result.election.gov.np/Images/symbol-hor-pa/${symbolId}.jpg?v=0.2`;
}

function getTwoThirdsMark() {
    if (!state.meta) {
        return 0;
    }

    return Math.ceil((state.meta.totalSeats * 2) / 3);
}

function mergeDatasets(payload) {
    const byParty = new Map();

    payload.pr.forEach((party) => {
        byParty.set(party.partyName, {
            partyName: party.partyName,
            symbolId: party.symbolId,
            prVotes: party.prVotes,
            prVoteShare: party.prVoteShare,
            prEstimatedSeats: party.prEstimatedSeats,
            fptpWon: 0,
            fptpLeading: 0,
            fptpProjectedSeats: 0,
            candidateCount: 0,
        });
    });

    payload.fptp.forEach((party) => {
        const existing = byParty.get(party.partyName) || {
            partyName: party.partyName,
            symbolId: party.symbolId,
            prVotes: 0,
            prVoteShare: 0,
            prEstimatedSeats: 0,
            fptpWon: 0,
            fptpLeading: 0,
            fptpProjectedSeats: 0,
            candidateCount: 0,
        };

        existing.symbolId = existing.symbolId || party.symbolId;
        existing.fptpWon = party.fptpWon;
        existing.fptpLeading = party.fptpLeading;
        existing.fptpProjectedSeats = party.fptpProjectedSeats;
        existing.candidateCount = party.candidateCount;

        byParty.set(party.partyName, existing);
    });

    return Array.from(byParty.values()).map((party) => ({
        ...party,
        securedFederalSeats: party.prEstimatedSeats + party.fptpWon,
        projectedFederalSeats: party.prEstimatedSeats + party.fptpProjectedSeats,
    }));
}

function getComparator(key, direction) {
    const factor = direction === "asc" ? 1 : -1;

    return (left, right) => {
        const leftValue = left[key];
        const rightValue = right[key];

        if (key === "partyName") {
            return leftValue.localeCompare(rightValue) * factor;
        }

        if (rightValue === leftValue) {
            return left.partyName.localeCompare(right.partyName);
        }

        return (leftValue - rightValue) * factor;
    };
}

function getFilteredData() {
    const query = searchInput.value.trim().toLowerCase();
    const selectedView = chamberFilter.value;
    const selectedSort = sortBy.value;
    const direction = sortDirection.value;

    return state.data
        .filter((party) => {
            if (query && !party.partyName.toLowerCase().includes(query)) {
                return false;
            }

            if (onlyProjected.checked && party.projectedFederalSeats < 1) {
                return false;
            }

            if (selectedView === "pr") {
                return party.prVotes > 0 || party.prEstimatedSeats > 0;
            }

            if (selectedView === "fptp") {
                return party.fptpWon > 0 || party.fptpLeading > 0 || party.candidateCount > 0;
            }

            return true;
        })
        .sort(getComparator(selectedSort, direction));
}

function renderHeroMeta(meta) {
    heroMeta.innerHTML = "";

    [
        `PR seats: ${formatNumber(meta.prSeats)}`,
        `FPTP seats: ${formatNumber(meta.fptpSeats)}`,
        `House total: ${formatNumber(meta.totalSeats)}`,
        `2/3 mark: ${formatNumber(Math.ceil((meta.totalSeats * 2) / 3))}`,
        `Updated: ${formatTime(state.lastUpdated)}`,
    ].forEach((text) => {
        const chip = document.createElement("div");
        chip.className = "hero-chip";
        chip.textContent = text;
        heroMeta.appendChild(chip);
    });
}

function renderSummary(filteredData) {
    if (!state.meta) {
        return;
    }

    const leader = filteredData[0] || state.data[0];
    const withProjection = state.data.filter((party) => party.projectedFederalSeats > 0).length;
    const supermajorityMark = getTwoThirdsMark();
    const seatsToSupermajority = leader
        ? Math.max(supermajorityMark - leader.projectedFederalSeats, 0)
        : 0;

    const cards = [
        {
            label: "Projected front-runner",
            value: leader ? formatNumber(leader.projectedFederalSeats) : "0",
            note: leader ? `${leader.partyName} projected federal seats` : "No data available",
        },
        {
            label: "Two-thirds threshold",
            value: formatNumber(supermajorityMark),
            note: "Seats required for a single-party constitutional-amendment supermajority",
        },
        {
            label: "Gap to two-thirds",
            value: formatNumber(seatsToSupermajority),
            note: leader
                ? `${leader.partyName} still needs this many seats to reach constitutional-amendment territory`
                : "No data available",
        },
        {
            label: "Parties in projection",
            value: formatNumber(withProjection),
            note: `Showing ${formatNumber(filteredData.length)} filtered rows from ${formatNumber(state.data.length)} total parties`,
        },
    ];

    summaryGrid.innerHTML = cards
        .map(
            (card) => `
        <article class="summary-card">
          <div class="summary-label">${card.label}</div>
          <div class="summary-value">${card.value}</div>
          <div class="summary-note">${card.note}</div>
        </article>
      `,
        )
        .join("");
}

function renderConclusion(filteredData) {
    const leader = filteredData[0] || null;

    if (!leader) {
        conclusionBody.innerHTML = '<div class="status-card">No party matches the current filters.</div>';
        return;
    }

    const supermajorityMark = getTwoThirdsMark();
    const seatsToSupermajority = Math.max(supermajorityMark - leader.projectedFederalSeats, 0);
    const seatsToMajority = Math.max(SIMPLE_MAJORITY_MARK - leader.projectedFederalSeats, 0);
    const federalShare = state.meta ? leader.projectedFederalSeats / state.meta.totalSeats : 0;
    const supermajorityStatus =
        leader.projectedFederalSeats >= supermajorityMark
            ? `${leader.partyName} is already above the two-thirds line, which would amount to single-party constitutional-amendment strength.`
            : `${leader.partyName} remains ${formatNumber(seatsToSupermajority)} seats short of the two-thirds line, so it is not yet in single-party constitutional-amendment territory.`;

    conclusionBody.innerHTML = `
    <article class="conclusion-lead">
      <p class="conclusion-kicker">Two-thirds watch</p>
      <h3 class="conclusion-title">${leader.partyName}</h3>
      <p class="conclusion-copy">
        ${leader.partyName} is currently projected to reach ${formatNumber(leader.projectedFederalSeats)} seats in the federal parliament, combining ${formatNumber(leader.prEstimatedSeats)} estimated PR seats with ${formatNumber(leader.fptpWon)} won and ${formatNumber(leader.fptpLeading)} leading FPTP seats. ${supermajorityStatus}
      </p>
    </article>
    <ul class="conclusion-list">
      <li>Projected share of the House: ${formatPercent(federalShare)}</li>
      <li>Seats still short of a simple majority: ${formatNumber(seatsToMajority)}</li>
      <li>Seats still short of a two-thirds supermajority: ${formatNumber(seatsToSupermajority)}</li>
      <li>Current secured floor using PR estimate plus FPTP wins: ${formatNumber(leader.securedFederalSeats)}</li>
    </ul>
  `;
}

function renderSeatMap(filteredData) {
    if (!state.meta || !state.data.length) {
        seatMap.innerHTML = '<div class="status-card">Waiting for seat data.</div>';
        seatLegend.innerHTML = "";
        return;
    }

    const colorMap = getPartyColorMap();
    const visibleParties = new Set(filteredData.map((party) => party.partyName));
    const seats = [];

    [...state.data]
        .sort((left, right) => right.projectedFederalSeats - left.projectedFederalSeats)
        .forEach((party) => {
            for (let seat = 0; seat < party.projectedFederalSeats; seat += 1) {
                seats.push({
                    partyName: party.partyName,
                    color: colorMap.get(party.partyName) || getFallbackColor(party.partyName),
                    visible: visibleParties.size === state.data.length || visibleParties.has(party.partyName),
                });
            }
        });

    const positions = buildSeatPositions(state.meta.totalSeats);
    seatMap.innerHTML = `
    <svg class="seat-svg" viewBox="0 0 720 430" role="img" aria-label="Federal parliament seat map showing projected party seat counts.">
      <g id="seatDots"></g>
      <g>
        <text x="360" y="234" text-anchor="middle" font-size="18" font-weight="700" fill="#62564a">Projected chamber</text>
        <text x="360" y="274" text-anchor="middle" font-size="58" font-weight="800" fill="#1d1812">${formatNumber(state.meta.totalSeats)}</text>
        <text x="360" y="307" text-anchor="middle" font-size="16" fill="#62564a">2/3 threshold: ${formatNumber(getTwoThirdsMark())} seats</text>
      </g>
    </svg>
  `;

    const seatDots = seatMap.querySelector("#seatDots");

    seats.forEach((seat, index) => {
        const position = positions[index];
        if (!position) {
            return;
        }

        const circle = document.createElementNS("http://www.w3.org/2000/svg", "circle");
        circle.setAttribute("cx", position.x.toFixed(2));
        circle.setAttribute("cy", position.y.toFixed(2));
        circle.setAttribute("r", "6.2");
        circle.setAttribute("fill", seat.color);
        circle.setAttribute("fill-opacity", seat.visible ? "1" : "0.16");
        seatDots.appendChild(circle);
    });

    renderLegend(
        seatLegend,
        [...state.data]
            .sort((left, right) => right.projectedFederalSeats - left.projectedFederalSeats)
            .slice(0, 8),
        colorMap,
        "projectedFederalSeats",
    );
}

function renderHeatMap() {
    if (!heatMap.dataset.embedded) {
        heatMap.innerHTML = `
      <iframe
        class="heat-frame"
        title="Official Nepal constituency winner map"
        loading="lazy"
        src="https://result.election.gov.np/MapElectionResult2082.aspx"
        referrerpolicy="no-referrer"
      ></iframe>
    `;
        heatMap.dataset.embedded = "true";
    }

    mapLegend.innerHTML = `
    <div class="legend-item">
      <span>Live official constituency map from Election Commission, Nepal</span>
    </div>
  `;
}

function renderVisuals(filteredData) {
    renderSeatMap(filteredData);
    renderHeatMap();
}

function renderTable(filteredData) {
    if (!filteredData.length) {
        resultsBody.innerHTML = `
      <tr>
        <td colspan="6">
          <div class="status-card">No parties match the current filters.</div>
        </td>
      </tr>
    `;
        return;
    }

    const rows = filteredData
        .map((party) => {
            const projectedWidth = state.meta
                ? Math.max((party.projectedFederalSeats / state.meta.totalSeats) * 100, 2)
                : 0;
            const symbolUrl = getSymbolUrl(party.symbolId);

            return `
        <tr>
          <td>
            <div class="party-cell">
              ${symbolUrl ? `<img class="symbol" src="${symbolUrl}" alt="${party.partyName} symbol" />` : ""}
              <div>
                <div class="party-name">${party.partyName}</div>
                <div class="party-subtle">Candidates: ${formatNumber(party.candidateCount)}</div>
              </div>
            </div>
          </td>
          <td>
            <div class="metric-stack">
              <span class="metric-strong">${formatNumber(party.prVotes)}</span>
              <span class="party-subtle">${formatPercent(party.prVoteShare)}</span>
            </div>
          </td>
          <td>${formatNumber(party.prEstimatedSeats)}</td>
          <td>${formatNumber(party.fptpWon)}</td>
          <td>${formatNumber(party.fptpLeading)}</td>
          <td>
            <div class="metric-stack">
              <span class="metric-strong">${formatNumber(party.projectedFederalSeats)}</span>
              <div class="seat-bar" aria-hidden="true">
                <div class="seat-fill" style="width: ${projectedWidth}%"></div>
              </div>
            </div>
          </td>
        </tr>
      `;
        })
        .join("");

    resultsBody.innerHTML = rows;
}

function render() {
    const filteredData = getFilteredData();
    renderSummary(filteredData);
    renderConclusion(filteredData);
    renderVisuals(filteredData);
    renderTable(filteredData);

    tableMeta.textContent = `Showing ${formatNumber(filteredData.length)} parties sorted by ${sortBy.options[sortBy.selectedIndex].text.toLowerCase()}.`;
    filterSummary.textContent = `${formatNumber(filteredData.length)} parties match the current search and filters.`;
}

async function loadData() {
    resultsBody.innerHTML = `
    <tr>
      <td colspan="6">
        <div class="status-card">Loading live results.</div>
      </td>
    </tr>
  `;

    try {
        const response = await fetch("api/results.php", { cache: "no-store" });
        const payload = await response.json();

        if (!response.ok || payload.error) {
            throw new Error(payload.error || "Unable to load election data.");
        }

        state.data = mergeDatasets(payload).sort(
            (left, right) => right.projectedFederalSeats - left.projectedFederalSeats,
        );
        state.meta = payload.meta;
        state.lastUpdated = payload.meta.generatedAt;

        renderHeroMeta(payload.meta);
        render();
    } catch (error) {
        resultsBody.innerHTML = `
      <tr>
        <td colspan="6">
          <div class="status-card error">${error.message}</div>
        </td>
      </tr>
    `;
        conclusionBody.innerHTML = `<div class="status-card error">${error.message}</div>`;
        summaryGrid.innerHTML = `<div class="status-card error">${error.message}</div>`;
        tableMeta.textContent = "The dashboard could not load upstream election data.";
        filterSummary.textContent = "Refresh to try again.";
        seatMap.innerHTML = `<div class="status-card error">${error.message}</div>`;
        heatMap.innerHTML = `<div class="status-card error">${error.message}</div>`;
    }
}

[searchInput, chamberFilter, sortBy, sortDirection, onlyProjected].forEach((element) => {
    element.addEventListener("input", render);
    element.addEventListener("change", render);
});

refreshButton.addEventListener("click", loadData);

resetButton.addEventListener("click", () => {
    searchInput.value = "";
    chamberFilter.value = "all";
    sortBy.value = "projectedFederalSeats";
    sortDirection.value = "desc";
    onlyProjected.checked = false;
    render();
});

loadData();