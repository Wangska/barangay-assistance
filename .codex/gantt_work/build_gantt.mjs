import fs from "node:fs/promises";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const sourcePath = "C:/Users/Jake/Downloads/SeniorCare.xlsx";
const outputDir = "outputs/gantt-chart-development";
const input = await FileBlob.load(sourcePath);
const workbook = await SpreadsheetFile.importXlsx(input);
const sheet = workbook.worksheets.getItem("Development");

for (const table of sheet.tables.items) table.delete();
sheet.deleteAllDrawings();
sheet.getRange("A1:R80").clear({ applyTo: "all" });
sheet.showGridLines = false;

const monday = (offset) => new Date(Date.UTC(2026, 8, 7 + offset * 7));
const date = (iso) => new Date(`${iso}T00:00:00Z`);
const weeks = Array.from({ length: 12 }, (_, i) => monday(i));

const phases = [
  ["1", "Planning and Design", "Project Manager", "Alfanta", "2026-09-07", "2026-09-18", "phase"],
  ["1.1", "Requirements gathering and scope definition", "Project Manager", "Alfanta", "2026-09-07", "2026-09-11", "task"],
  ["1.2", "Process mapping and database design", "System Analyst", "Dela Cerna", "2026-09-14", "2026-09-18", "task"],
  ["1.3", "Interface wireframes and project setup", "Developer", "Paragat", "2026-09-07", "2026-09-18", "task"],
  ["2", "Core System", "Project Manager", "Alfanta", "2026-09-14", "2026-10-09", "phase"],
  ["2.1", "Development environment and database implementation", "Developer", "Paragat", "2026-09-14", "2026-09-18", "task"],
  ["2.2", "Authentication and role-based access", "Developer", "Paragat", "2026-09-21", "2026-09-25", "task"],
  ["2.3", "Senior registration and profile management", "Developer", "Paragat", "2026-09-28", "2026-10-02", "task"],
  ["2.4", "Administrator registration approval and ID review", "Developer", "Paragat", "2026-10-05", "2026-10-09", "task"],
  ["3", "Barangay Service Features", "Project Manager", "Alfanta", "2026-10-05", "2026-10-16", "phase"],
  ["3.1", "Assistance request workflow and status tracking", "Developer", "Paragat", "2026-10-05", "2026-10-09", "task"],
  ["3.2", "Announcements and events management", "Developer", "Paragat", "2026-10-05", "2026-10-09", "task"],
  ["3.3", "Senior portal dashboard and notifications", "Developer", "Paragat", "2026-10-12", "2026-10-16", "task"],
  ["3.4", "SMS gateway integration and delivery logs", "Developer", "Paragat", "2026-10-12", "2026-10-16", "task"],
  ["4", "Administration and Security", "Project Manager", "Alfanta", "2026-10-19", "2026-10-30", "phase"],
  ["4.1", "Staff dashboard and senior records", "Developer", "Paragat", "2026-10-19", "2026-10-23", "task"],
  ["4.2", "Reports, audit logs, settings, and backups", "Developer", "Paragat", "2026-10-19", "2026-10-23", "task"],
  ["4.3", "Security hardening and access-control review", "Developer", "Paragat", "2026-10-26", "2026-10-30", "task"],
  ["5", "Testing, Deployment, and Handover", "Project Manager", "Alfanta", "2026-11-02", "2026-11-20", "phase"],
  ["5.1", "Integration and user-acceptance testing", "Tester", "Tangpos", "2026-11-02", "2026-11-06", "task"],
  ["5.2", "Bug fixes and performance tuning", "Developer", "Paragat", "2026-11-09", "2026-11-13", "task"],
  ["5.3", "Documentation, deployment, and staff orientation", "Project Manager", "Alfanta", "2026-11-16", "2026-11-20", "task"],
];

sheet.mergeCells("C1:R1");
sheet.getRange("A1").values = [["PROJECT TITLE"]];
sheet.getRange("C1").values = [["Senior Citizen Assistance System for Barangay Services"]];
sheet.getRange("A2:F2").values = [["WBS\nNUMBER", "TASK TITLE", "TASK OWNER", "NAME", "START\nDATE", "DUE\nDATE"]];
sheet.getRange("G2:J2").merge();
sheet.getRange("K2:N2").merge();
sheet.getRange("O2:R2").merge();
sheet.getRange("G2:R2").values = [["SEPTEMBER 2026", null, null, null, "OCTOBER 2026", null, null, null, "NOVEMBER 2026", null, null, null]];
sheet.getRange("G3:R3").values = [weeks];
sheet.getRange("G3:R3").format.numberFormat = "mmm d";

const data = phases.map((row) => [row[0], row[1], row[2], row[3], date(row[4]), date(row[5])]);
sheet.getRange(`A4:F${3 + data.length}`).values = data;
sheet.getRange(`E4:F${3 + data.length}`).format.numberFormat = "mmm d, yyyy";
for (let row = 4; row < 4 + phases.length; row++) {
  const isPhase = phases[row - 4][6] === "phase";
  const formula = `=IF(AND(G$3<=$F${row},G$3+6>=$E${row}),\"■\",\"\")`;
  sheet.getRange(`G${row}`).formulas = [[formula]];
  sheet.getRange(`G${row}:R${row}`).fillRight();
  if (isPhase) {
    sheet.getRange(`A${row}:R${row}`).format = {
      fill: "#DCE6F1",
      font: { bold: true, color: "#17365D" },
      verticalAlignment: "center",
    };
    sheet.getRange(`G${row}:R${row}`).format.font = { bold: true, color: "#1F4E78" };
  } else {
    sheet.getRange(`A${row}:R${row}`).format.verticalAlignment = "center";
    sheet.getRange(`G${row}:R${row}`).format.font = { color: "#4F81BD" };
  }
}

const lastRow = 3 + phases.length;
sheet.getRange("A1:R1").format = { fill: "#1F4E78", font: { bold: true, color: "#FFFFFF", size: 12 }, verticalAlignment: "center" };
sheet.getRange("A2:F2").format = { fill: "#366092", font: { bold: true, color: "#FFFFFF" }, horizontalAlignment: "center", verticalAlignment: "center", wrapText: true };
sheet.getRange("G2:R2").format = { fill: "#366092", font: { bold: true, color: "#FFFFFF" }, horizontalAlignment: "center", verticalAlignment: "center" };
sheet.getRange("G3:R3").format = { fill: "#B8CCE4", font: { bold: true, color: "#17365D" }, horizontalAlignment: "center", verticalAlignment: "center" };
sheet.getRange(`A2:R${lastRow}`).format.borders = { preset: "all", style: "thin", color: "#D9E2F3" };
sheet.getRange(`A4:A${lastRow}`).format.horizontalAlignment = "center";
sheet.getRange(`C4:D${lastRow}`).format.horizontalAlignment = "center";
sheet.getRange(`E4:F${lastRow}`).format.horizontalAlignment = "center";
sheet.getRange(`G4:R${lastRow}`).format.horizontalAlignment = "center";
sheet.getRange(`A4:R${lastRow}`).format.wrapText = true;

sheet.getRange("A1").format.columnWidth = 11;
sheet.getRange("B1").format.columnWidth = 47;
sheet.getRange("C1").format.columnWidth = 16;
sheet.getRange("D1").format.columnWidth = 15;
sheet.getRange("E1:F1").format.columnWidth = 14;
sheet.getRange("G1:R1").format.columnWidth = 10;
sheet.getRange("A1:R1").format.rowHeight = 26;
sheet.getRange("A2:R2").format.rowHeight = 32;
sheet.getRange("A3:R3").format.rowHeight = 22;
sheet.getRange(`A4:R${lastRow}`).format.rowHeight = 28;
sheet.freezePanes.freezeRows(3);
sheet.freezePanes.freezeColumns(2);

const legendRow = lastRow + 3;
sheet.mergeCells(`A${legendRow}:F${legendRow}`);
sheet.getRange(`A${legendRow}`).values = [["Legend: ■ Planned development period | Schedule: 7 September – 20 November 2026 (12 weeks)"]];
sheet.getRange(`A${legendRow}:R${legendRow}`).format = { fill: "#EAF2F8", font: { italic: true, color: "#17365D" }, verticalAlignment: "center" };
sheet.getRange(`A${legendRow}:R${legendRow}`).format.borders = { preset: "outside", style: "thin", color: "#B8CCE4" };
sheet.getRange(`A${legendRow}:R${legendRow}`).format.rowHeight = 24;

const check = await workbook.inspect({ kind: "table", sheetId: "Development", range: `A1:R${legendRow}`, include: "values,formulas", tableMaxRows: 35, tableMaxCols: 18, maxChars: 10000 });
console.log(check.ndjson);
const errors = await workbook.inspect({ kind: "match", searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A", options: { useRegex: true, maxResults: 100 }, summary: "final formula error scan" });
console.log(errors.ndjson);
const preview = await workbook.render({ sheetName: "Development", range: `A1:R${legendRow}`, scale: 1, format: "png" });
await fs.mkdir(outputDir, { recursive: true });
await fs.writeFile(`${outputDir}/development-preview.png`, new Uint8Array(await preview.arrayBuffer()));
const output = await SpreadsheetFile.exportXlsx(workbook);
await output.save(`${outputDir}/SeniorCare_Development_Gantt.xlsx`);
