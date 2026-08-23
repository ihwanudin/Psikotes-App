import openpyxl
from .common import write


def extract(root):
    wb=openpyxl.load_workbook(root/"Update DASS"/"Bank Narasi Formula HPP Psikotes.xlsx",data_only=True)
    ga=wb["3. Grey Area"]
    fields=[]
    for r in range(33,39):
        raised=[part.strip().split()[0] for part in str(ga.cell(r,6).value).split(";") if "->" in part]
        interest=str(ga.cell(r,5).value).split()[0] if ga.cell(r,5).value!="-" else None
        fields.append({"code":ga.cell(r,2).value,"required_interest":interest,"raised_to_4":raised})
    nar=wb["2. Narasi Aspek"]
    narratives=[{"key":nar.cell(r,1).value,"aspect":nar.cell(r,2).value,"level":int(nar.cell(r,5).value),"id":nar.cell(r,10).value,"jp":nar.cell(r,11).value} for r in range(2,92)]
    con=wb["12. Konektor Narasi"]
    connectors=[{"group":con.cell(r,1).value,"order":con.cell(r,3).value,"text":con.cell(r,4).value} for r in range(2,14) if con.cell(r,1).value]
    data={"standard_version":ga.cell(50,2).value,"fields":fields,"critical":["A1","B2","C4","C5"],"narratives":narratives,"connectors":connectors}
    write("reporting.json",data); return data
